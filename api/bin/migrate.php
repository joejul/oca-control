<?php

declare(strict_types=1);

/**
 * Aplica los archivos SQL de migrations/ que aun no se han corrido.
 * Uso:  php api/bin/migrate.php   (o  ddev exec php api/bin/migrate.php)
 */

use App\Database;

/** @var App\Config $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

$pdo = Database::init($config);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT filename FROM schema_migrations')
    ->fetchAll(PDO::FETCH_COLUMN) ?: [];

$dir   = dirname(__DIR__) . '/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    echo "Aplicando $name ... ";
    $sql = file_get_contents($file);
    if ($sql === false) {
        echo "ERROR al leer\n";
        exit(1);
    }
    $pdo->exec($sql);
    $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (:f)')
        ->execute(['f' => $name]);
    echo "ok\n";
    $ran++;
}

echo $ran === 0 ? "Sin migraciones pendientes.\n" : "$ran migracion(es) aplicada(s).\n";
