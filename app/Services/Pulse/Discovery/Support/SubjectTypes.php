<?php

namespace App\Services\Pulse\Discovery\Support;

use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\PulseRole;
use Illuminate\Database\Eloquent\Model;

/**
 * pulse_signals.subject_type holds the kind of thing a signal points at. The indexer may write a short name
 * ("offer") or a model class; both mean the same here. Short names are the canonical form this module writes.
 */
final class SubjectTypes
{
    /** @var array<string, class-string<Model>> */
    public const MODELS = [
        'offer' => PulseOffer::class,
        'need' => PulseNeed::class,
        'role' => PulseRole::class,
        'profile' => PulseProfile::class,
        'post' => PulsePost::class,
        'entity' => PulseEntity::class,
    ];

    /** "App\Models\PulseOffer" / "pulse_offer" / "Offer" -> "offer"; unknown values come back lowercased. */
    public static function normalise(string $type): string
    {
        $base = strtolower(class_basename(str_replace('.', '\\', $type)));
        $base = preg_replace('/^pulse[_-]?/', '', $base) ?? $base;

        return match ($base) {
            'talent', 'professional' => 'profile',
            'business', 'company' => 'entity',
            default => $base,
        };
    }

    /**
     * Every stored spelling of a canonical type, for WHERE IN clauses.
     *
     * @return list<string>
     */
    public static function variants(string $canonical): array
    {
        $out = [$canonical];

        if (isset(self::MODELS[$canonical])) {
            $out[] = self::MODELS[$canonical];
        }

        return $out;
    }
}
