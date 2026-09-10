<?php

declare(strict_types=1);

namespace App;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class Router
{
    /** @var array<int,array{method:string,regex:string,params:string[],handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_]+)\}/', static function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    public function get(string $p, callable $h): void
    {
        $this->add('GET', $p, $h);
    }

    public function post(string $p, callable $h): void
    {
        $this->add('POST', $p, $h);
    }

    public function patch(string $p, callable $h): void
    {
        $this->add('PATCH', $p, $h);
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            $args = [];
            foreach ($route['params'] as $i => $name) {
                $args[$name] = $m[$i + 1];
            }

            $result = ($route['handler'])($request, $args);
            return $result instanceof Response ? $result : Response::json($result);
        }

        throw $pathMatched
            ? new HttpException(405, 'Metodo no permitido')
            : HttpException::notFound('Ruta no encontrada: ' . $request->path);
    }
}
