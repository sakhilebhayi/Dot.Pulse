<?php

namespace App\Livewire\Pulse\Floor\Concerns;

/** The reasons of a stored match in the shape a beat carries them. */
final class BeatReasons
{
    /**
     * @param  array<int, mixed>  $reasons
     * @return list<array{code: string, text: string, weight: float, ref: ?string}>
     */
    public static function fromMatch(array $reasons): array
    {
        $out = [];

        foreach ($reasons as $r) {
            if (is_array($r) && isset($r['code'])) {
                $out[] = [
                    'code' => (string) $r['code'],
                    'text' => (string) ($r['text'] ?? ''),
                    'weight' => (float) ($r['weight'] ?? 0),
                    'ref' => isset($r['ref']) ? (string) $r['ref'] : null,
                ];
            }
        }

        return $out;
    }
}
