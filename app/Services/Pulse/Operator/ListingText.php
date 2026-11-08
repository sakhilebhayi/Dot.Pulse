<?php

namespace App\Services\Pulse\Operator;

use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseRole;
use Illuminate\Database\Eloquent\Model;

/**
 * The public text of a listing, put together the way the content gate puts it together when the listing is
 * submitted, so the moderator sees the flags the same words would raise today.
 */
final class ListingText
{
    public static function of(Model $subject): string
    {
        $parts = match (true) {
            $subject instanceof PulseOffer => [$subject->title, $subject->summary, $subject->description, $subject->category, implode(' ', (array) $subject->tags)],
            $subject instanceof PulseRole => [$subject->title, $subject->summary, $subject->description, implode(' ', (array) $subject->skills)],
            $subject instanceof PulseNeed => [$subject->title, $subject->details, $subject->budget_text, implode(' ', (array) $subject->tags)],
            $subject instanceof PulseEntity => [$subject->name, $subject->tagline, $subject->about],
            default => [],
        };

        return implode("\n", array_filter($parts, fn ($p) => is_string($p) && trim($p) !== ''));
    }

    public static function kind(Model $subject): string
    {
        return match (true) {
            $subject instanceof PulseOffer => 'offer',
            $subject instanceof PulseRole => 'role',
            $subject instanceof PulseNeed => 'need',
            default => 'entity',
        };
    }
}
