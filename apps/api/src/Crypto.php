<?php

declare(strict_types=1);

namespace Cockpit;

/** AES-256-GCM for OAuth tokens at rest. Key: 32 bytes, base64 in ENCRYPTION_KEY. */
final class Crypto
{
    private string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('ENCRYPTION_KEY muss 32 Byte als Base64 sein (openssl rand -base64 32).');
        }
        $this->key = $key;
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Verschlüsselung fehlgeschlagen.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        if (!str_starts_with($encoded, 'v1:')) {
            throw new \RuntimeException('Unbekanntes Format des verschlüsselten Werts.');
        }
        $raw = base64_decode(substr($encoded, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Verschlüsselter Wert ist beschädigt.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new \RuntimeException('Entschlüsselung fehlgeschlagen. Wurde ENCRYPTION_KEY geändert?');
        }
        return $plain;
    }
}
