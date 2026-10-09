<?php

declare(strict_types=1);

namespace Cockpit;

/**
 * Reads settings from a .env file that lives outside the web root.
 * Real environment variables win over the file.
 */
final class Config
{
    /** @param array<string, string> $values */
    private function __construct(private array $values)
    {
    }

    public static function load(?string $file = null): self
    {
        // Lookup: ANALYTICS_ENV_FILE (e.g. SetEnv in .htaccess) → path in env-path.txt next to src/
        // (written on deploy, points outside the web root) → analytics.env next to src/ (folder is denied by .htaccess).
        if ($file === null) {
            $pointer = dirname(__DIR__) . '/env-path.txt';
            $file = getenv('ANALYTICS_ENV_FILE')
                ?: (is_file($pointer) ? trim((string) file_get_contents($pointer)) : dirname(__DIR__) . '/analytics.env');
        }
        $values = is_file($file) ? self::parse((string) file_get_contents($file)) : [];
        foreach ($values as $key => $_) {
            $env = getenv($key);
            if ($env !== false) {
                $values[$key] = $env;
            }
        }
        foreach (self::KNOWN as $key) {
            $env = getenv($key);
            if ($env !== false && !isset($values[$key])) {
                $values[$key] = $env;
            }
        }
        return new self($values);
    }

    /** @param array<string, string> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /** @return array<string, string> */
    public static function parse(string $content): array
    {
        $out = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (preg_match('/^(["\'])(.*)\1$/s', $value, $m)) {
                $value = $m[2];
            }
            $out[$key] = $value;
        }
        return $out;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? null;
        return ($value === null || $value === '') ? $default : $value;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new \RuntimeException("Einstellung {$key} fehlt in der analytics.env.");
        }
        return $value;
    }

    public function bool(string $key): bool
    {
        return in_array(strtolower((string) $this->get($key, '')), ['1', 'true', 'yes', 'on'], true);
    }

    public function isDev(): bool
    {
        return $this->get('APP_ENV') === 'dev';
    }

    private const KNOWN = [
        'APP_ENV', 'API_BASE_PATH', 'DATABASE_PATH', 'BACKUP_DIR', 'ENCRYPTION_KEY', 'ADMIN_TOKEN_SECRET', 'CRON_KEY',
        'ADMIN_SESSION_NAME', 'ADMIN_SESSION_KEY', 'ADMIN_LOGIN_URL', 'ADMIN_URL', 'DEV_AUTH_USER',
        'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI', 'YT_CHANNEL_ID',
        'APPLE_PODCAST_ID', 'APPLE_STOREFRONTS', 'FEED_URL', 'PUBLIC_ORIGIN', 'ACCESS_LOG_DIR',
        'ACCESS_LOG_GLOB', 'OP3_TOKEN', 'OP3_SHOW_UUID', 'ROUTINE_FIRE_URL', 'ROUTINE_FIRE_TOKEN',
        'ANTHROPIC_API_KEY', 'ANTHROPIC_MODEL',
        'META_APP_ID', 'META_APP_SECRET', 'META_REDIRECT_URI', 'META_CONFIG_ID', 'META_PAGE_ID', 'META_GRAPH_VERSION',
    ];
}
