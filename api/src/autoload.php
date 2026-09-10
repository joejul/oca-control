<?php
// Autoloader minimo para App\ -> src/  (fallback si no se corrio "composer install").
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, 4)) . '.php';
    $path = __DIR__ . '/' . $rel;
    if (is_file($path)) {
        require $path;
    }
});
