<?php

namespace App\Services\Pulse\Discovery\Support;

/** Tiny array helpers for filter handling. */
final class Arr
{
    /**
     * A filter given as a string or a list becomes a clean list of strings.
     *
     * @return list<string>
     */
    public static function kinds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('strval', (array) $value), fn ($v) => $v !== '')));
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public static function only(array $filters): array
    {
        return array_intersect_key($filters, array_flip(['kind', 'mode', 'place', 'remote', 'price_min', 'price_max', 'employment_type']));
    }
}
