<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseMute;
use App\Models\User;
use Illuminate\Support\Collection;

/** Quiet, one way filters: a topic, a business page or a person the Floor and notifications stop showing. */
class MuteService
{
    public const MAX = 200;

    public function mute(User $u, string $kind, string $value): PulseMute
    {
        $value = $this->normalise($kind, $value);

        $existing = PulseMute::query()->where(['user_id' => $u->id, 'kind' => $kind, 'target' => $value])->first();
        if ($existing) {
            return $existing;
        }

        if (PulseMute::query()->where('user_id', $u->id)->count() >= self::MAX) {
            throw LineException::because('limit', 'You have muted as much as Pulse can hold. Unmute something first.');
        }

        $mute = PulseMute::create(['user_id' => $u->id, 'kind' => $kind, 'target' => $value]);
        AuditLog::record($u, 'mute.add', $mute, ['kind' => $kind]);

        return $mute;
    }

    public function unmute(User $u, string $kind, string $value): void
    {
        $value = $this->normalise($kind, $value);

        PulseMute::query()->where(['user_id' => $u->id, 'kind' => $kind, 'target' => $value])->delete();
    }

    public static function isMuted(User $u, string $kind, string|int $value): bool
    {
        return PulseMute::query()->where(['user_id' => $u->id, 'kind' => $kind, 'target' => mb_strtolower((string) $value)])->exists();
    }

    /** @return Collection<int, PulseMute> */
    public function all(User $u): Collection
    {
        return PulseMute::query()->where('user_id', $u->id)->orderBy('kind')->orderBy('target')->get();
    }

    /**
     * Muted values of one kind, for a query that must exclude them.
     *
     * @return list<string>
     */
    public static function mutedValues(User $u, string $kind): array
    {
        return PulseMute::query()->where(['user_id' => $u->id, 'kind' => $kind])->pluck('target')->all();
    }

    private function normalise(string $kind, string $value): string
    {
        if (! in_array($kind, PulseMute::KINDS, true)) {
            throw LineException::because('invalid', 'You can mute a topic, a business or a person.');
        }

        $value = mb_strtolower(trim($value));

        if ($value === '' || mb_strlen($value) > 120) {
            throw LineException::because('invalid', 'That is not something Pulse can mute.');
        }

        return $value;
    }
}
