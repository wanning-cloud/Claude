<?php

declare(strict_types=1);

namespace Cockpit;

/** Outbound HTTP. Connectors get it injected so tests can replay recorded responses. */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array;
}
