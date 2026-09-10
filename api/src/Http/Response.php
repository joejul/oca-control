<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public function __construct(
        public int $status = 200,
        public mixed $data = null,
        /** @var array<string,string> */
        public array $headers = [],
        public ?string $rawBody = null,
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, $data);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public static function csv(string $body, string $filename): self
    {
        return new self(200, null, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ], $body);
    }

    public static function pdf(string $body, string $filename): self
    {
        return new self(200, null, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ], $body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        if ($this->rawBody !== null) {
            echo $this->rawBody;
            return;
        }
        if ($this->status === 204 || $this->data === null) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
