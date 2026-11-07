<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseEntity;
use App\Models\PulseModerationLog;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulsePost;
use App\Models\PulseRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Decides the moderation state of public text before it can publish.
 *
 * With App\Services\Pulse\Discovery\Moderation\LocalRules present, its verdict rules: allow = approved,
 * hold = held (a human looks), block = the write is refused with the stated reasons.
 *
 * Without it (the discovery branch not merged, or the rules failing), nothing is trusted by default:
 * - approved only for an author with a confirmed InfoDot company link (teams.infodot_team_uuid) AND a clean history
 *   (no removed or held listing, no removed or rejected post of theirs);
 * - everyone else is pending, and a pending listing is not shown to anyone but its owners and moderators.
 */
class ContentGate
{
    public const LOCAL_RULES = 'App\\Services\\Pulse\\Discovery\\Moderation\\LocalRules';

    /**
     * @param  array<string, mixed>  $context
     * @return array{moderation: string, source: string, flags: list<string>}
     *
     * @throws ValidationException when the rules block the text
     */
    public function decide(User $author, string $text, array $context = []): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['moderation' => 'approved', 'source' => 'empty', 'flags' => []];
        }

        $verdict = $this->localRules($author, $text, $context);

        if ($verdict !== null) {
            $flags = array_values(array_map('strval', (array) ($verdict['flags'] ?? [])));

            return match ($verdict['verdict'] ?? null) {
                'allow' => ['moderation' => 'approved', 'source' => 'local_rules', 'flags' => $flags],
                'block' => throw ValidationException::withMessages([
                    'content' => 'This cannot be published'.($flags ? ' ('.implode(', ', $flags).')' : '').'. Edit it and try again.',
                ]),
                default => ['moderation' => 'held', 'source' => 'local_rules', 'flags' => $flags],
            };
        }

        if (InfoDotLink::has($author) && $this->cleanHistory($author)) {
            return ['moderation' => 'approved', 'source' => 'infodot_member', 'flags' => []];
        }

        return ['moderation' => 'pending', 'source' => 'no_rules', 'flags' => []];
    }

    /** @return array<string, mixed>|null */
    private function localRules(User $author, string $text, array $context): ?array
    {
        $class = (string) (config('pulse.entities.rules_class') ?: self::LOCAL_RULES); // the config key lets tests choose the rules

        if (! class_exists($class)) {
            return null;
        }

        try {
            $result = app($class)->assess($text, $author, $context);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return is_array($result) && isset($result['verdict']) ? $result : null;
    }

    public function cleanHistory(User $user): bool
    {
        $badModeration = ['removed', 'held'];

        if (PulseOffer::withTrashed()->where('created_by', $user->id)->whereIn('moderation', $badModeration)->exists()
            || PulseRole::withTrashed()->where('created_by', $user->id)->whereIn('moderation', $badModeration)->exists()
            || PulseNeed::withTrashed()->where('user_id', $user->id)->whereIn('moderation', $badModeration)->exists()
            || PulseEntity::withTrashed()->where('created_by', $user->id)->whereIn('moderation', $badModeration)->exists()) {
            return false;
        }

        $postIds = PulsePost::withTrashed()->where('user_id', $user->id)->pluck('id');

        if ($postIds->isEmpty()) {
            return true;
        }

        return ! PulseModerationLog::query()
            ->where('target_type', (new PulsePost)->getMorphClass())
            ->whereIn('target_id', $postIds)
            ->whereIn('action', ['remove', 'reject', 'warn'])
            ->exists();
    }
}
