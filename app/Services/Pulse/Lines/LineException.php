<?php

namespace App\Services\Pulse\Lines;

use RuntimeException;

/**
 * A refusal that is safe to show to the person: the message is plain words, `reason` is a stable code the UI can
 * branch on. Field level problems (a link in a card, a too long slip) are ValidationException instead, so they land
 * on the right input. Reasons: not_allowed, budget, age, unavailable, not_open, invalid, exists, limit.
 */
class LineException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'not_allowed')
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $message): self
    {
        return new self($message, $reason);
    }
}
