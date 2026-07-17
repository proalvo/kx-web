<?php
declare(strict_types=1);

namespace KxWeb;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    /** @param array{dsn:string,user:string,password:string} $dbConfig */
    public static function pdo(array $dbConfig): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                $dbConfig['dsn'],
                $dbConfig['user'],
                $dbConfig['password'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }
        return self::$pdo;
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // version 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
