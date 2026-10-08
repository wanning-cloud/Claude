<?php

declare(strict_types=1);

namespace Cockpit\Http;

final class Request
{
    /** @var array<string, string> */
    public array $params = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers lower-case names
     * @param array<string, mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly array $files = [],
        /** @var array<string, mixed> */
        public readonly array $post = [],
    ) {
    }

    public static function fromGlobals(string $basePath): self
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = str_starts_with($uri, $basePath) ? substr($uri, strlen($basePath)) : $uri;
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim($path, '/'),
            $_GET,
            $headers,
            (string) file_get_contents('php://input'),
            $_FILES,
            $_POST,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function q(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        try {
            $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'Die Anfrage enthält kein gültiges JSON.');
        }
        if (!is_array($data)) {
            throw new HttpException(400, 'Die Anfrage muss ein JSON-Objekt sein.');
        }
        return $data;
    }

    public function isWrite(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
