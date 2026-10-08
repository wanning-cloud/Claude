<?php

declare(strict_types=1);

namespace Cockpit\Auth;

use Cockpit\Config;
use Cockpit\Http\HttpException;
use Cockpit\Http\Request;

/**
 * One login: the API lives under /podcast-admin/ and reads the session of the upload helper
 * (read-only, never writes to it). podcast-admin/index.php uses session_name('mp_admin') with the
 * cookie path /podcast-admin/ and sets $_SESSION['ok'] = true after login (docs/podcast-admin-analyse.md).
 *
 * Writes additionally need an X-CSRF-Token header: HMAC of the session id, so nothing is stored.
 */
final class AdminSession
{
    private ?string $user = null;
    private ?string $sessionId = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function authenticate(Request $request): void
    {
        $devUser = $this->config->get('DEV_AUTH_USER');
        // Local development (php -S) and automated tests only; never active on the server.
        $local = ($this->config->isDev() && PHP_SAPI === 'cli-server') || ($this->config->get('APP_ENV') === 'test' && PHP_SAPI === 'cli');
        if ($devUser !== null && $local) {
            $this->user = $devUser;
            $this->sessionId = 'dev';
        } else {
            $this->readAdminSession();
        }
        if ($this->user === null) {
            throw new HttpException(401, 'Nicht angemeldet. Bitte im podcast-admin anmelden.', [
                'loginUrl' => $this->loginUrl(),
            ]);
        }
        if ($request->isWrite() && !hash_equals($this->csrf(), (string) $request->header('x-csrf-token'))) {
            throw new HttpException(403, 'Sicherheitsprüfung fehlgeschlagen. Bitte die Seite neu laden.');
        }
    }

    private function readAdminSession(): void
    {
        $name = $this->config->get('ADMIN_SESSION_NAME', 'mp_admin');
        $key = $this->config->get('ADMIN_SESSION_KEY', 'ok');
        if (!isset($_COOKIE[$name]) || !preg_match('/^[A-Za-z0-9,-]{16,128}$/', (string) $_COOKIE[$name])) {
            return;
        }
        session_name($name);
        session_start(['read_and_close' => true, 'use_strict_mode' => true]);
        $value = $_SESSION;
        foreach (explode('.', $key) as $part) {
            $value = is_array($value) ? ($value[$part] ?? null) : null;
        }
        if ($value) {
            $this->user = is_string($value) ? $value : 'Markus';
            $this->sessionId = session_id() ?: null;
        }
    }

    public function user(): string
    {
        return $this->user ?? 'system';
    }

    public function csrf(): string
    {
        return hash_hmac('sha256', 'csrf|' . ($this->sessionId ?? ''), $this->config->require('ADMIN_TOKEN_SECRET'));
    }

    public function loginUrl(): string
    {
        return $this->config->get('ADMIN_LOGIN_URL', '/podcast-admin/');
    }

    public function adminUrl(): string
    {
        return $this->config->get('ADMIN_URL', '/podcast-admin/');
    }
}
