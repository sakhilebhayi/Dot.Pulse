<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseKnockBudget;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The allowance of first contacts to strangers (D18). 7 a week for an established account, 3 for an account under
 * 7 days old. A reply from a stranger refills one (at most 5 a window, so two accounts cannot pump each other);
 * confirmed spam shrinks the allowance and the shrink heals by one each clean window. It cannot be bought: there is
 * no method here that adds to it for money. Spending is one conditional UPDATE, so two requests racing for the last
 * knock cannot both win. The remaining count is cached for a minute for display; spending never reads the cache.
 */
class KnockBudget
{
    public function remaining(User $u): int
    {
        return Cache::remember($this->cacheKey($u), 60, fn () => $this->compute($this->row($u), $u));
    }

    /** @throws LineException when nothing is left this window. */
    public function spend(User $u): void
    {
        $row = $this->row($u);
        $base = $this->base($u);

        $affected = PulseKnockBudget::query()
            ->whereKey($row->id)
            ->whereRaw('spent < (CASE WHEN penalty > CAST(? AS INTEGER) THEN 0 ELSE CAST(? AS INTEGER) - penalty END) + bonus', [$base, $base])
            ->update(['spent' => DB::raw('spent + 1'), 'updated_at' => now()]);

        $this->forget($u);

        if ($affected === 0) {
            throw LineException::because('budget', 'You have used this week\'s first contacts. They refill on '
                .$this->resetsAt($u)->format('j M').' or when someone replies to you.');
        }
    }

    /** A stranger replied: one more first contact this window, up to the cap. */
    public function refill(User $u): void
    {
        $row = $this->row($u);
        $cap = (int) config('pulse.lines.knock_budget.bonus_cap', 5);

        PulseKnockBudget::query()->whereKey($row->id)->where('bonus', '<', $cap)
            ->update(['bonus' => DB::raw('bonus + 1'), 'updated_at' => now()]);

        $this->forget($u);
    }

    /** Confirmed spam: the allowance gets smaller. Never below zero extra, never more than the base allowance. */
    public function shrink(User $u, int $by = 1): void
    {
        $row = $this->row($u);
        $penalty = min($this->base($u), $row->penalty + max(0, $by));

        $row->forceFill(['penalty' => $penalty])->save();
        $this->forget($u);
    }

    public function resetsAt(User $u): Carbon
    {
        return $this->row($u)->window_started_at->copy()->addDays($this->windowDays());
    }

    /** The allowance this account starts each window with, before refills and shrinks. */
    public function base(User $u): int
    {
        $cfg = config('pulse.lines.knock_budget');
        $isNew = $u->created_at !== null && $u->created_at->gt(now()->subDays((int) ($cfg['new_days'] ?? 7)));

        return (int) ($isNew ? ($cfg['new'] ?? 3) : ($cfg['established'] ?? 7));
    }

    private function compute(PulseKnockBudget $row, User $u): int
    {
        $base = $this->base($u);
        $cap = max(0, $base - $row->penalty) + $row->bonus;

        return max(0, $cap - $row->spent);
    }

    /** The row for this window, created on first use and rolled over when the window has ended. */
    private function row(User $u): PulseKnockBudget
    {
        $row = PulseKnockBudget::query()->where('user_id', $u->id)->first();

        if ($row === null) {
            try {
                // A savepoint, so a lost race on first use does not poison an enclosing PostgreSQL transaction.
                $row = DB::transaction(fn () => PulseKnockBudget::create(['user_id' => $u->id, 'window_started_at' => now(), 'spent' => 0, 'bonus' => 0, 'penalty' => 0]));
            } catch (UniqueConstraintViolationException) {
                $row = PulseKnockBudget::query()->where('user_id', $u->id)->firstOrFail();
            }

            return $row;
        }

        $window = $this->windowDays();
        $elapsed = (int) $row->window_started_at->diffInDays(now(), false);

        if ($elapsed >= $window) {
            $windows = intdiv($elapsed, $window);
            $started = $row->window_started_at->copy()->addDays($windows * $window);

            // Conditional on the old start, so two requests rolling the same window do it once.
            $rolled = PulseKnockBudget::query()->whereKey($row->id)->where('window_started_at', $row->window_started_at)
                ->update([
                    'window_started_at' => $started,
                    'spent' => 0,
                    'bonus' => 0,
                    'penalty' => DB::raw('CASE WHEN penalty > '.$windows.' THEN penalty - '.$windows.' ELSE 0 END'),
                    'updated_at' => now(),
                ]);

            if ($rolled) {
                $this->forget($u);
            }

            $row = $row->fresh();
        }

        return $row;
    }

    private function windowDays(): int
    {
        return max(1, (int) config('pulse.lines.knock_budget.window_days', 7));
    }

    private function cacheKey(User $u): string
    {
        return 'pulse:knock-remaining:'.$u->id;
    }

    private function forget(User $u): void
    {
        Cache::forget($this->cacheKey($u));
    }
}
