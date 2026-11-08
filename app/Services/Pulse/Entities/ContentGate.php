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
     * @return array{moderation: string, source: string, flags: list<string>, reason: ?string, details: list<array{code: string, severity: string, text: string}>}
     *
     * @throws ValidationException when the rules block the text
     */
    public function decide(User $author, string $text, array $context = []): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['moderation' => 'approved', 'source' => 'empty', 'flags' => [], 'reason' => null, 'details' => []];
        }

        $verdict = $this->localRules($author, $text, $context);

        if ($verdict !== null) {
            // LocalRules returns each flag as an array with a `code`; test fakes return plain strings.
            $flags = array_values(array_map(
                fn ($f) => is_array($f) ? (string) ($f['code'] ?? $f['reason'] ?? 'flag') : (string) $f,
                (array) ($verdict['flags'] ?? []),
            ));

            $details = array_values(array_map(
                fn ($f) => is_array($f)
                    ? ['code' => (string) ($f['code'] ?? 'flag'), 'severity' => (string) ($f['severity'] ?? 'hold'), 'text' => (string) ($f['text'] ?? '')]
                    : ['code' => (string) $f, 'severity' => 'hold', 'text' => ucfirst(str_replace('_', ' ', (string) $f))],
                (array) ($verdict['flags'] ?? []),
            ));
            $reason = isset($verdict['reason']) && filled($verdict['reason']) ? (string) $verdict['reason'] : ($details[0]['text'] ?? null);

            return match ($verdict['verdict'] ?? null) {
                'allow' => ['moderation' => 'approved', 'source' => 'local_rules', 'flags' => $flags, 'reason' => null, 'details' => []],
                'block' => throw ValidationException::withMessages([
                    'content' => 'This cannot be published'.($flags ? ' ('.implode(', ', $flags).')' : '').'. Edit it and try again.',
                ]),
                default => ['moderation' => 'held', 'source' => 'local_rules', 'flags' => $flags, 'reason' => $reason, 'details' => $details],
            };
        }

        if (InfoDotLink::has($author) && $this->cleanHistory($author)) {
            return ['moderation' => 'approved', 'source' => 'infodot_member', 'flags' => [], 'reason' => null, 'details' => []];
        }

        return ['moderation' => 'pending', 'source' => 'no_rules', 'flags' => [], 'reason' => 'No automatic check ran, so a person looks first. Linking an InfoDot company you belong to helps next time.', 'details' => []];
    }

    /**
     * The columns that record a decision, so a service stores the state and the reason together. Approved clears
     * any earlier reason: the author is never shown a stale "waiting for a human".
     *
     * @param  array<string, mixed>  $decision  from decide()
     * @return array{moderation: string, moderation_reason: ?string, moderation_flags: ?array}
     */
    public function stamp(array $decision): array
    {
        $approved = ($decision['moderation'] ?? null) === 'approved';

        return [
            'moderation' => $decision['moderation'],
            'moderation_reason' => $approved ? null : ($decision['reason'] ?? null),
            'moderation_flags' => $approved || empty($decision['details']) ? null : $decision['details'],
        ];
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
