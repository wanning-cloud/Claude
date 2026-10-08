<?php

declare(strict_types=1);

namespace Cockpit;

final class CurlHttpClient implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        $ch = curl_init($url);
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_USERAGENT => 'Podcast-Cockpit/1.0 (+https://monteur-podcast.de)',
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            throw new \RuntimeException("Verbindung zu " . parse_url($url, PHP_URL_HOST) . " fehlgeschlagen: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['status' => $status, 'body' => (string) $result, 'headers' => $responseHeaders];
    }
}
