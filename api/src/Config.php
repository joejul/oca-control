<?php

declare(strict_types=1);

namespace App;

final class Config
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            $example = dirname($path) . '/config.php.example';
            throw new \RuntimeException(
                "No existe config.php. Copia config.php.example a config.php ($example)."
            );
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new \RuntimeException('config.php debe devolver un array.');
        }
        return new self($data);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function db(): array
    {
        return $this->data['db'] ?? [];
    }

    public function timezone(): string
    {
        return $this->data['timezone'] ?? 'America/Costa_Rica';
    }

    public function apiPrefix(): string
    {
        return rtrim((string) ($this->data['api_prefix'] ?? ''), '/');
    }

    /** @return string[] */
    public function corsOrigins(): array
    {
        return $this->data['cors_origins'] ?? [];
    }

    public function tokenTtlDays(): int
    {
        return (int) ($this->data['token_ttl_days'] ?? 30);
    }

    public function maxLoginAttempts(): int
    {
        return (int) ($this->data['max_login_attempts'] ?? 5);
    }

    public function lockoutMinutes(): int
    {
        return (int) ($this->data['lockout_minutes'] ?? 15);
    }

    /** @return array<string,mixed> */
    public function mail(): array
    {
        return $this->data['mail'] ?? [];
    }
}
