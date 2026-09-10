<?php

declare(strict_types=1);

namespace App\Http;

final class HttpException extends \RuntimeException
{
    /** @var array<string,mixed> */
    public array $details;

    /** @param array<string,mixed> $details */
    public function __construct(int $status, string $message, array $details = [])
    {
        parent::__construct($message, $status);
        $this->details = $details;
    }

    public static function badRequest(string $msg, array $details = []): self
    {
        return new self(400, $msg, $details);
    }

    public static function unauthorized(string $msg = 'No autenticado'): self
    {
        return new self(401, $msg);
    }

    public static function forbidden(string $msg = 'No autorizado'): self
    {
        return new self(403, $msg);
    }

    public static function notFound(string $msg = 'No encontrado'): self
    {
        return new self(404, $msg);
    }

    public static function unprocessable(string $msg, array $details = []): self
    {
        return new self(422, $msg, $details);
    }

    public static function tooManyRequests(string $msg): self
    {
        return new self(429, $msg);
    }
}
