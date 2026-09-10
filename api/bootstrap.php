<?php
/**
 * Punto de arranque comun: carga el autoloader, la config y fija la zona horaria.
 * Devuelve la instancia de App\Config.
 */

declare(strict_types=1);

$vendor = __DIR__ . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    require __DIR__ . '/src/autoload.php';
}

use App\Config;

$config = Config::load(__DIR__ . '/config.php');
date_default_timezone_set($config->timezone());

return $config;
