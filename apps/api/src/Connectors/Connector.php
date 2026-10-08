<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

interface Connector
{
    /** Stable id, used in sync_runs and POST /api/sync/{id}. */
    public function id(): string;

    public function label(): string;

    /** Returns a short German summary; throws with a plain-language message on failure. */
    public function sync(): SyncOutcome;
}
