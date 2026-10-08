<?php

declare(strict_types=1);

namespace Cockpit\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(public int $status = 200, public string $body = '', public array $headers = [])
    {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    public static function redirect(string $url): self
    {
        return new self(302, '', ['Location' => $url]);
    }

    public static function error(int $status, string $message, array $extra = []): self
    {
        return self::json(['error' => $message] + $extra, $status);
    }

    /** Weak ETag over the body; answers 304 when the client already has it. */
    public function withEtag(Request $request): self
    {
        if ($this->status !== 200 || $request->method !== 'GET') {
            return $this;
        }
        $etag = 'W/"' . substr(hash('sha256', $this->body), 0, 32) . '"';
        $this->headers['ETag'] = $etag;
        if ($request->header('if-none-match') === $etag) {
            $this->status = 304;
            $this->body = '';
        }
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
