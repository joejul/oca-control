<?php

declare(strict_types=1);

/**
 * Crea el admin y los colaboradores iniciales si no existen.
 * NO borra nada. Los PIN de ejemplo se imprimen en consola.
 * Uso:  php api/bin/seed.php   (o  ddev exec php api/bin/seed.php)
 */

use App\Auth;
use App\Database;

/** @var App\Config $config */
$config = require dirname(__DIR__) . '/bootstrap.php';
$pdo = Database::init($config);

$people = [
    ['username' => 'joe',     'display_name' => 'Joe',      'role' => 'admin',        'pin' => '091192'],
    ['username' => 'josue',   'display_name' => 'Josue',    'role' => 'collaborator', 'pin' => 'j7637'],
    ['username' => 'gitzy',   'display_name' => 'Gitzy',    'role' => 'collaborator', 'pin' => 'g6621'],
    ['username' => 'daniel',  'display_name' => 'Daniel',   'role' => 'collaborator', 'pin' => 'd3232'],
    ['username' => 'marcela', 'display_name' => 'Marcela',  'role' => 'collaborator', 'pin' => 'm5937'],
    ['username' => 'nela',    'display_name' => 'Nela',     'role' => 'collaborator', 'pin' => 'n5516'],
    ['username' => 'patricia','display_name' => 'Patricia', 'role' => 'collaborator', 'pin' => 'p3497'],
    ['username' => 'will',    'display_name' => 'Will',     'role' => 'collaborator', 'pin' => 'w8434'],
    ['username' => 'vanessa', 'display_name' => 'Vanessa',  'role' => 'collaborator', 'pin' => 'v4139'],
    ['username' => 'adriana', 'display_name' => 'Adriana',  'role' => 'collaborator', 'pin' => 'a1341'],
    // Cuenta compartida (Fer/Joseph/Julian) solo para el modulo Agenda.
    ['username' => 'ocaso',   'display_name' => 'OCASO', 'role' => 'collaborator', 'pin' => 'ocaso0909'],
];

$insert = $pdo->prepare(
    'INSERT INTO users (username, display_name, role, credential_hash)
     VALUES (:u, :d, :r, :h)'
);
$exists = $pdo->prepare('SELECT 1 FROM users WHERE username = :u');

$created = 0;
echo str_pad('USUARIO', 12) . str_pad('NOMBRE', 12) . str_pad('ROL', 14) . "PIN\n";
echo str_repeat('-', 44) . "\n";
foreach ($people as $p) {
    $exists->execute(['u' => $p['username']]);
    if ($exists->fetchColumn()) {
        echo str_pad($p['username'], 12) . str_pad($p['display_name'], 12)
            . str_pad($p['role'], 14) . "(ya existe)\n";
        continue;
    }
    $insert->execute([
        'u' => $p['username'],
        'd' => $p['display_name'],
        'r' => $p['role'],
        'h' => Auth::hashSecret($p['pin']),
    ]);
    $created++;
    echo str_pad($p['username'], 12) . str_pad($p['display_name'], 12)
        . str_pad($p['role'], 14) . $p['pin'] . "\n";
}

echo str_repeat('-', 44) . "\n";
echo "$created usuario(s) creado(s).\n";
echo "IMPORTANTE: cambia estos PIN antes de usar en produccion.\n";
