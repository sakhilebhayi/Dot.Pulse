<?php

namespace App\Services\Pulse\Entities;

use Illuminate\Support\Str;

class Slugger
{
    /** Words that would collide with routes under /b/. */
    public const RESERVED = ['new', 'manage', 'admin', 'api', 'edit', 'create', 'o', 'r', 'null', 'undefined'];

    /**
     * A slug for $base that $taken says is free. Tries base, base-2, base-3 ... then a random suffix, so
     * concurrent creators still end up with different slugs (the unique index is the final word).
     *
     * @param  callable(string): bool  $taken
     */
    public static function unique(string $base, callable $taken, int $max = 100): string
    {
        $slug = Str::slug(Str::limit($base, 80, ''));

        if ($slug === '' || in_array($slug, self::RESERVED, true)) {
            $slug = ($slug === '' ? 'page' : $slug.'-page');
        }

        $slug = Str::limit($slug, $max - 8, '');
        $candidate = $slug;

        for ($i = 2; $i <= 30 && $taken($candidate); $i++) {
            $candidate = $slug.'-'.$i;
        }

        while ($taken($candidate)) {
            $candidate = $slug.'-'.Str::lower(Str::random(6));
        }

        return $candidate;
    }
}
