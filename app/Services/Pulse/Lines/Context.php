<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseDoor;
use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseProfile;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The thing a line is about: an offer, a role, a need, a door or a signal. Knows whether it is live, whose it is,
 * and how to copy it into a snapshot so the line keeps its meaning if the listing changes later.
 * A snapshot never carries the identity of an anonymous professional.
 */
class Context
{
    public static function isLive(Model $m): bool
    {
        return match (true) {
            $m instanceof PulseOffer => $m->status === 'live' && $m->moderation === 'approved'
                && ($m->expires_at === null || $m->expires_at->isFuture()),
            $m instanceof PulseRole => $m->status === 'live' && $m->moderation === 'approved'
                && ($m->closes_at === null || $m->closes_at->isFuture()),
            $m instanceof PulseNeed => $m->status === 'open' && $m->moderation === 'approved'
                && ($m->expires_at === null || $m->expires_at->isFuture()),
            $m instanceof PulseDoor => $m->isOpen(),
            $m instanceof PulseSignal => $m->status === 'live' && ($m->expires_at === null || $m->expires_at->isFuture()),
            default => false,
        };
    }

    /** Whether the thing belongs to the target: "reaches me through something of mine". */
    public static function belongsToTarget(Model $m, ?User $targetUser, ?PulseEntity $targetEntity): bool
    {
        return match (true) {
            $m instanceof PulseOffer, $m instanceof PulseRole => $targetEntity !== null && $m->entity_id === $targetEntity->id,
            $m instanceof PulseNeed => $targetUser !== null && $m->user_id === $targetUser->id,
            $m instanceof PulseDoor => ($targetUser !== null && $m->owner_id === $targetUser->id)
                || ($targetEntity !== null && $m->entity_id === $targetEntity->id),
            $m instanceof PulseSignal => ($targetUser !== null && $m->owner_id === $targetUser->id)
                || ($targetEntity !== null && $m->entity_id === $targetEntity->id),
            default => false,
        };
    }

    /**
     * @return array{type: string, id: int, title: string, snapshot: array<string, mixed>}
     */
    public static function describe(Model $m): array
    {
        return match (true) {
            $m instanceof PulseOffer => [
                'type' => 'offer', 'id' => $m->id, 'title' => $m->title,
                'snapshot' => array_filter([
                    'title' => $m->title, 'summary' => $m->summary, 'category' => $m->category,
                    'price' => self::priceText($m), 'from' => $m->entity?->name,
                ]),
            ],
            $m instanceof PulseRole => [
                'type' => 'role', 'id' => $m->id, 'title' => $m->title,
                'snapshot' => array_filter([
                    'title' => $m->title, 'employment_type' => $m->employment_type, 'work_mode' => $m->work_mode,
                    'pay' => $m->pay_text, 'from' => $m->entity?->name,
                ]),
            ],
            $m instanceof PulseNeed => [
                'type' => 'need', 'id' => $m->id, 'title' => $m->title,
                'snapshot' => array_filter([
                    'title' => $m->title, 'details' => $m->details ? mb_substr($m->details, 0, 240) : null,
                    'budget' => $m->budget_text, 'needed_by' => $m->needed_by?->toDateString(),
                ]),
            ],
            $m instanceof PulseDoor => [
                'type' => 'door', 'id' => $m->id, 'title' => $m->purpose,
                'snapshot' => ['purpose' => $m->purpose, 'closes_at' => $m->closes_at?->toIso8601String()],
            ],
            $m instanceof PulseSignal => [
                'type' => 'signal', 'id' => $m->id, 'title' => $m->title,
                'snapshot' => self::signalSnapshot($m),
            ],
            $m instanceof PulseEntity => [
                'type' => 'entity', 'id' => $m->id, 'title' => $m->name, 'snapshot' => ['name' => $m->name],
            ],
            default => [
                'type' => 'profile', 'id' => (int) $m->getKey(), 'title' => 'a profile', 'snapshot' => [],
            ],
        };
    }

    /** What an employer may see of an anonymous professional: headline, skills, an experience band and a region. */
    public static function anonymousCard(PulseSignal $signal): array
    {
        $years = PulseProfile::query()->where('user_id', $signal->owner_id)->value('experience_years');

        return array_filter([
            'headline' => $signal->title,
            'skills' => array_slice((array) $signal->skills, 0, 8),
            'experience_band' => self::experienceBand($years),
            'region' => $signal->place?->name,
        ]);
    }

    public static function experienceBand(?int $years): ?string
    {
        return match (true) {
            $years === null => null,
            $years <= 2 => '0-2 years',
            $years <= 5 => '3-5 years',
            $years <= 10 => '6-10 years',
            default => '10+ years',
        };
    }

    private static function signalSnapshot(PulseSignal $s): array
    {
        if ($s->kind === 'talent' && $s->anonymous) {
            return ['anonymous' => true] + self::anonymousCard($s);
        }

        return array_filter(['title' => $s->title, 'kind' => $s->kind, 'excerpt' => $s->excerpt]);
    }

    private static function priceText(PulseOffer $o): ?string
    {
        return match ($o->price_mode) {
            'fixed' => $o->amount_cents !== null ? Money::text($o->amount_cents, $o->currency).($o->unit ? ' '.$o->unit : '') : null,
            'from' => $o->amount_cents !== null ? 'From '.Money::text($o->amount_cents, $o->currency).($o->unit ? ' '.$o->unit : '') : null,
            'quote' => 'Quote on request',
            'free' => 'Free',
            default => null,
        };
    }
}
