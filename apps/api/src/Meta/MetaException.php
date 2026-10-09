<?php

declare(strict_types=1);

namespace Cockpit\Meta;

/** Graph API error with the Meta error code, so callers can treat "not enough data" and unknown metrics differently. */
final class MetaException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $metaCode = 0,
        public readonly int $httpStatus = 0,
        public readonly string $metaMessage = '',
    ) {
        parent::__construct($message);
    }

    /** "(#10) Not enough viewers for the media to show insights": small posts and stories under 5 views. */
    public function isNotEnoughData(): bool
    {
        return $this->metaCode === 10 && stripos($this->metaMessage, 'not enough') !== false;
    }

    /** Invalid or deprecated metric name, or a field this media type does not support (Meta renames metrics often). */
    public function isInvalidParameter(): bool
    {
        return $this->metaCode === 100;
    }

    public function isAuthError(): bool
    {
        return $this->metaCode === 190 || $this->httpStatus === 401;
    }
}
