<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @param array<string,mixed> $body @param array<string,string> $query */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $body,
        public readonly array $query,
        private readonly ?string $bearerToken,
    ) {
    }

    public static function fromGlobals(string $apiPrefix): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        $path   = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path   = rawurldecode($path);

        if ($apiPrefix !== '' && str_starts_with($path, $apiPrefix)) {
            $path = substr($path, strlen($apiPrefix));
        }
        $path = '/' . trim($path, '/');

        $raw  = file_get_contents('php://input') ?: '';
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
        if ($body === [] && !empty($_POST)) {
            $body = $_POST;
        }

        $token   = null;
        $headers = self::headers();
        $auth    = $headers['authorization'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            $token = trim($m[1]);
        }

        return new self($method, $path, $body, $_GET, $token);
    }

    /** @return array<string,string> */
    private static function headers(): array
    {
        $out = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $out[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $out['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        return $out;
    }

    public function bearerToken(): ?string
    {
        return $this->bearerToken;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function queryParam(string $key, ?string $default = null): ?string
    {
        $v = $this->query[$key] ?? $default;
        return $v === null ? null : (string) $v;
    }
}
