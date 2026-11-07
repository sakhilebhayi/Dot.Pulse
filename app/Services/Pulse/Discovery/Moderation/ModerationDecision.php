<?php

namespace App\Services\Pulse\Discovery\Moderation;

/**
 * What the first pass decided about one piece of text, in the vocabularies the rest of Pulse stores:
 * moderation (the offer/role/need column: approved, held, removed) and signalStatus (live, held, removed).
 */
final class ModerationDecision
{
    /**
     * @param  list<array{code: string, severity: string, text: string}>  $flags
     */
    public function __construct(
        public readonly string $verdict,
        public readonly array $flags,
        public readonly ?string $reason,
        public readonly int $score,
    ) {}

    public function allowed(): bool
    {
        return $this->verdict === LocalRules::ALLOW;
    }

    public function held(): bool
    {
        return $this->verdict === LocalRules::HOLD;
    }

    public function blocked(): bool
    {
        return $this->verdict === LocalRules::BLOCK;
    }

    /** The value for pulse_offers/pulse_roles/pulse_needs.moderation. */
    public function moderation(): string
    {
        return match ($this->verdict) {
            LocalRules::ALLOW => 'approved',
            LocalRules::HOLD => 'held',
            default => 'removed',
        };
    }

    /** The value for pulse_signals.status. */
    public function signalStatus(): string
    {
        return match ($this->verdict) {
            LocalRules::ALLOW => 'live',
            LocalRules::HOLD => 'held',
            default => 'removed',
        };
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_column($this->flags, 'code');
    }

    public function toArray(): array
    {
        return ['verdict' => $this->verdict, 'moderation' => $this->moderation(), 'reason' => $this->reason, 'flags' => $this->flags, 'score' => $this->score];
    }
}
