<?php

declare(strict_types=1);

namespace Cockpit\Http;

/** Error with a German message that is safe to show in the UI. */
final class HttpException extends \RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(public readonly int $status, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
