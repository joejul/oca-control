<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Kernel;

/** @var App\Config $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

// --- CORS (para el dev server de Vite) ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $config->corsOrigins(), true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');
}

try {
    $request  = Request::fromGlobals($config->apiPrefix());
    $response = (new Kernel($config))->handle($request);
} catch (\Throwable $e) {
    error_log('[oca-control] bootstrap ' . $e);
    $response = Response::json(['error' => 'Error interno del servidor.'], 500);
}

$response->send();
