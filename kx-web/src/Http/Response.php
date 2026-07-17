<?php
declare(strict_types=1);

namespace KxWeb\Http;

final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly array $headers,
    ) {
    }

    /** @param array<string,string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null',
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers,
        );
    }

    /** @param array<string,string> $headers */
    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8'] + $headers);
    }

    public static function notModified(string $etag): self
    {
        return new self(304, '', ['ETag' => $etag]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->status !== 304) {
            echo $this->body;
        }
    }
}
