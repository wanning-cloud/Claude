<?php

declare(strict_types=1);

namespace Cockpit\Meta;

use Cockpit\Clock;
use Cockpit\Config;
use Cockpit\Crypto;
use Cockpit\Db;
use Cockpit\HttpClient;

/**
 * Facebook Login for the page "Der Monteur Podcast" and its linked Instagram professional account.
 * Flow: code → short-lived user token → long-lived user token → page token. A page token derived
 * from a long-lived user token has no expiry; it stops working when Markus changes his password or
 * removes the app. It is stored AES-GCM encrypted like the YouTube token.
 */
final class MetaAuth
{
    /** Read posts, insights and comments; reply to comments. Nothing is ever published. */
    public const SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'pages_read_user_content',
        'pages_manage_engagement',
        'read_insights',
        'instagram_basic',
        'instagram_manage_insights',
        'instagram_manage_comments',
    ];

    public function __construct(
        private readonly Db $db,
        private readonly HttpClient $http,
        private readonly Config $config,
        private readonly Crypto $crypto,
    ) {
    }

    public function version(): string
    {
        return $this->config->get('META_GRAPH_VERSION', MetaApi::DEFAULT_VERSION);
    }

    public function authorizationUrl(string $state): string
    {
        $params = [
            'client_id' => $this->config->require('META_APP_ID'),
            'redirect_uri' => $this->config->require('META_REDIRECT_URI'),
            'state' => $state,
            'response_type' => 'code',
        ];
        // Facebook Login for Business uses a configuration id that holds the permissions; classic login uses scope.
        $configId = $this->config->get('META_CONFIG_ID');
        if ($configId !== null) {
            $params['config_id'] = $configId;
        } else {
            $params['scope'] = implode(',', self::SCOPES);
        }
        return 'https://www.facebook.com/' . $this->version() . '/dialog/oauth?' . http_build_query($params);
    }

    /**
     * Exchanges the code and picks the page. @return array{page: string, instagram: string|null}
     */
    public function connect(string $code): array
    {
        $short = $this->token([
            'client_id' => $this->config->require('META_APP_ID'),
            'client_secret' => $this->config->require('META_APP_SECRET'),
            'redirect_uri' => $this->config->require('META_REDIRECT_URI'),
            'code' => $code,
        ]);
        $long = $this->token([
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->config->require('META_APP_ID'),
            'client_secret' => $this->config->require('META_APP_SECRET'),
            'fb_exchange_token' => $short,
        ]);
        $pages = $this->userGet('me/accounts', $long, ['fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => 100]);
        $page = self::pickPage($pages['data'] ?? [], $this->config->get('META_PAGE_ID'));
        $instagram = $page['instagram_business_account'] ?? null;
        $this->db->tx(function () use ($page, $instagram): void {
            $now = Clock::nowIso();
            $account = 'Facebook: ' . $page['name'] . ($instagram ? ' · Instagram: @' . $instagram['username'] : '');
            $this->db->run(
                "INSERT INTO connections (platform, access_token_enc, refresh_token_enc, expires_at, account, scopes, updated_at)
                 VALUES ('meta', ?, NULL, NULL, ?, ?, ?)
                 ON CONFLICT (platform) DO UPDATE SET access_token_enc = excluded.access_token_enc, refresh_token_enc = NULL,
                   expires_at = NULL, account = excluded.account, scopes = excluded.scopes, updated_at = excluded.updated_at",
                [$this->crypto->encrypt((string) $page['access_token']), $account, implode(' ', self::SCOPES), $now],
            );
            $this->db->setSetting('meta.page_id', (string) $page['id']);
            $this->db->setSetting('meta.page_name', (string) $page['name']);
            if ($instagram) {
                $this->db->setSetting('meta.ig_user_id', (string) $instagram['id']);
                $this->db->setSetting('meta.ig_username', (string) $instagram['username']);
            } else {
                $this->db->run("DELETE FROM settings WHERE key IN ('meta.ig_user_id', 'meta.ig_username')");
            }
        });
        return ['page' => (string) $page['name'], 'instagram' => $instagram ? (string) $instagram['username'] : null];
    }

    /**
     * META_PAGE_ID wins; otherwise the only page, or the one with "Monteur" in its name.
     * @param list<array<string, mixed>> $pages @return array<string, mixed>
     */
    public static function pickPage(array $pages, ?string $pageId): array
    {
        if ($pages === []) {
            throw new \RuntimeException('Meta hat keine Facebook-Seite freigegeben. Beim Verbinden die Seite „Der Monteur Podcast“ auswählen.');
        }
        foreach ($pages as $p) {
            if ($pageId !== null && (string) $p['id'] === $pageId) {
                return $p;
            }
        }
        if ($pageId !== null) {
            throw new \RuntimeException('Die Seite aus META_PAGE_ID wurde nicht freigegeben. Beim Verbinden die Seite „Der Monteur Podcast“ auswählen.');
        }
        if (count($pages) === 1) {
            return $pages[0];
        }
        $matches = array_values(array_filter($pages, static fn (array $p): bool => stripos((string) $p['name'], 'monteur') !== false));
        if (count($matches) === 1) {
            return $matches[0];
        }
        throw new \RuntimeException('Mehrere Facebook-Seiten freigegeben (' . implode(', ', array_map(static fn (array $p): string => (string) $p['name'], $pages)) . '). Bitte META_PAGE_ID in der analytics.env setzen.');
    }

    public function isConnected(): bool
    {
        return $this->db->value("SELECT access_token_enc FROM connections WHERE platform = 'meta'") !== null;
    }

    /** @return array{connected: bool, account: string|null, facebook: bool, instagram: bool, instagramUsername: string|null} */
    public function status(): array
    {
        $row = $this->db->one("SELECT access_token_enc, account FROM connections WHERE platform = 'meta'");
        $connected = $row !== null && $row['access_token_enc'] !== null;
        return [
            'connected' => $connected,
            'account' => $row['account'] ?? null,
            'facebook' => $connected && $this->pageId() !== null,
            'instagram' => $connected && $this->igUserId() !== null,
            'instagramUsername' => $connected ? $this->db->setting('meta.ig_username') : null,
        ];
    }

    public function pageToken(): string
    {
        $enc = $this->db->value("SELECT access_token_enc FROM connections WHERE platform = 'meta'");
        if ($enc === null) {
            throw new \RuntimeException('Meta ist nicht verbunden. Unter Automatik › Verbindungen „Meta verbinden“ klicken.');
        }
        return $this->crypto->decrypt((string) $enc);
    }

    /** access_token plus appsecret_proof (Meta's recommended protection for server calls). @return array<string, string> */
    public function tokenParams(): array
    {
        $token = $this->pageToken();
        $secret = $this->config->get('META_APP_SECRET');
        return ['access_token' => $token] + ($secret === null ? [] : ['appsecret_proof' => hash_hmac('sha256', $token, $secret)]);
    }

    public function pageId(): ?string
    {
        return $this->db->setting('meta.page_id');
    }

    public function igUserId(): ?string
    {
        return $this->db->setting('meta.ig_user_id');
    }

    public function igUsername(): ?string
    {
        return $this->db->setting('meta.ig_username');
    }

    public function disconnect(): void
    {
        $this->db->run("DELETE FROM connections WHERE platform = 'meta'");
        $this->db->run("DELETE FROM settings WHERE key LIKE 'meta.%'");
    }

    /** @param array<string, string> $params */
    private function token(array $params): string
    {
        $res = $this->http->request('GET', 'https://graph.facebook.com/' . $this->version() . '/oauth/access_token?' . http_build_query($params), ['Accept' => 'application/json']);
        $data = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($data) || empty($data['access_token'])) {
            $raw = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            throw new \RuntimeException("Anmeldung bei Meta fehlgeschlagen (HTTP {$res['status']}" . ($raw !== '' ? ": {$raw}" : '') . ').');
        }
        return (string) $data['access_token'];
    }

    /** @param array<string, string|int> $query @return array<string, mixed> */
    private function userGet(string $path, string $userToken, array $query): array
    {
        $query += ['access_token' => $userToken, 'appsecret_proof' => hash_hmac('sha256', $userToken, $this->config->require('META_APP_SECRET'))];
        $res = $this->http->request('GET', 'https://graph.facebook.com/' . $this->version() . '/' . $path . '?' . http_build_query($query), ['Accept' => 'application/json']);
        $data = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($data)) {
            $raw = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            throw new \RuntimeException("Meta-Seiten konnten nicht gelesen werden (HTTP {$res['status']}" . ($raw !== '' ? ": {$raw}" : '') . ').');
        }
        return $data;
    }
}
