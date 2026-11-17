<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function init(Config $config): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $db  = $config->db();
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $db['host'] ?? '127.0.0.1',
            $db['port'] ?? '3306',
            $db['name'] ?? '',
            $db['charset'] ?? 'utf8mb4'
        );

        self::$pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // El servidor de base de datos puede estar en UTC (Hostinger lo esta). Sin esto, NOW() y
        // created_at quedan 6 horas adelantados y todo lo registrado despues de las 6 pm de Costa
        // Rica queda con la fecha del dia siguiente, lo que descuadra los cierres. Se alinea la
        // sesion con la zona horaria de la app.
        $offset = (new \DateTimeImmutable('now', new \DateTimeZone($config->timezone())))->format('P');
        self::$pdo->exec("SET time_zone = '" . $offset . "'");

        return self::$pdo;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo instanceof PDO) {
            throw new \RuntimeException('Database::init() no fue llamado.');
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
