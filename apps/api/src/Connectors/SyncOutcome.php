<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

final class SyncOutcome
{
    public function __construct(public readonly int $items, public readonly string $message)
    {
    }
}
