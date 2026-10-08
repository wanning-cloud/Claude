<?php

declare(strict_types=1);

namespace Cockpit\Google;

use Cockpit\Clock;
use Cockpit\Config;
use Cockpit\Crypto;
use Cockpit\Db;
use Cockpit\HttpClient;

/** OAuth 2.0 for the YouTube Data and Analytics APIs. Tokens are stored AES-GCM encrypted. */
final class YouTubeAuth
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/youtube.force-ssl',
        'https://www.googleapis.com/auth/yt-analytics.readonly',
        'https://www.googleapis.com/auth/youtube.readonly',
    ];
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private readonly Db $db,
        private readonly HttpClient $http,
        private readonly Config $config,
        private readonly Crypto $crypto,
    ) {
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->config->require('GOOGLE_CLIENT_ID'),
            'redirect_uri' => $this->config->require('GOOGLE_REDIRECT_URI'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): void
    {
        $token = $this->tokenRequest([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->config->require('GOOGLE_REDIRECT_URI'),
        ]);
        if (empty($token['refresh_token'])) {
            throw new \RuntimeException('Google hat keinen Refresh-Token geliefert. Bitte unter myaccount.google.com › Sicherheit › Drittanbieter-Zugriff den Zugriff entfernen und erneut verbinden.');
        }
        $this->store($token, (string) $token['refresh_token']);
    }

    public function isConnected(): bool
    {
        return $this->db->value("SELECT refresh_token_enc FROM connections WHERE platform = 'youtube'") !== null;
    }

    /** @return array{connected: bool, expiresAt: string|null, account: string|null} */
    public function status(): array
    {
        $row = $this->db->one("SELECT refresh_token_enc, expires_at, account FROM connections WHERE platform = 'youtube'");
        return [
            'connected' => $row !== null && $row['refresh_token_enc'] !== null,
            'expiresAt' => null, // refresh tokens do not expire while the consent screen is "In production"
            'account' => $row['account'] ?? null,
        ];
    }

    public function accessToken(): string
    {
        $row = $this->db->one("SELECT access_token_enc, refresh_token_enc, expires_at FROM connections WHERE platform = 'youtube'");
        if ($row === null || $row['refresh_token_enc'] === null) {
            throw new \RuntimeException('YouTube ist nicht verbunden. Unter Automatik › Verbindungen „YouTube verbinden“ klicken.');
        }
        if ($row['access_token_enc'] !== null && $row['expires_at'] !== null && Clock::parse((string) $row['expires_at']) > Clock::now()->modify('+2 minutes')) {
            return $this->crypto->decrypt((string) $row['access_token_enc']);
        }
        $refresh = $this->crypto->decrypt((string) $row['refresh_token_enc']);
        $token = $this->tokenRequest(['refresh_token' => $refresh, 'grant_type' => 'refresh_token']);
        $this->store($token, $refresh);
        return (string) $token['access_token'];
    }

    public function setAccount(string $account): void
    {
        $this->db->run("UPDATE connections SET account = ? WHERE platform = 'youtube'", [$account]);
    }

    public function disconnect(): void
    {
        $this->db->run("DELETE FROM connections WHERE platform = 'youtube'");
    }

    /** @param array<string, string> $params @return array<string, mixed> */
    private function tokenRequest(array $params): array
    {
        $res = $this->http->request('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($params + [
            'client_id' => $this->config->require('GOOGLE_CLIENT_ID'),
            'client_secret' => $this->config->require('GOOGLE_CLIENT_SECRET'),
        ]));
        $data = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($data) || empty($data['access_token'])) {
            $error = is_array($data) ? (string) ($data['error'] ?? '') : '';
            if ($error === 'invalid_grant') {
                throw new \RuntimeException('Die YouTube-Verbindung ist abgelaufen oder wurde widerrufen. Bitte unter Automatik „YouTube verbinden“ erneut klicken.');
            }
            throw new \RuntimeException("Anmeldung bei Google fehlgeschlagen (HTTP {$res['status']} {$error}).");
        }
        return $data;
    }

    /** @param array<string, mixed> $token */
    private function store(array $token, string $refresh): void
    {
        $expires = Clock::now()->modify('+' . max(60, (int) ($token['expires_in'] ?? 3600)) . ' seconds');
        $this->db->run(
            "INSERT INTO connections (platform, access_token_enc, refresh_token_enc, expires_at, scopes, updated_at)
             VALUES ('youtube', ?, ?, ?, ?, ?)
             ON CONFLICT (platform) DO UPDATE SET access_token_enc = excluded.access_token_enc,
               refresh_token_enc = excluded.refresh_token_enc, expires_at = excluded.expires_at,
               scopes = excluded.scopes, updated_at = excluded.updated_at",
            [
                $this->crypto->encrypt((string) $token['access_token']),
                $this->crypto->encrypt($refresh),
                Clock::iso($expires),
                (string) ($token['scope'] ?? implode(' ', self::SCOPES)),
                Clock::nowIso(),
            ],
        );
    }
}
