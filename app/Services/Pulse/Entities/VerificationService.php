<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseEntity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * What can honestly be said about a business page. Nothing here is invented and nothing says "verified business".
 *
 * InfoDot exposes no business verification to Pulse (R41_INTEGRATIONS.md): the sign-in response lists the
 * companies a person belongs to (uuid, name, role) and nothing about them. So the ladder has three kinds of rung:
 *
 * - checked: read from a real source Pulse holds, with that source and a time. InfoDot team membership (the page is
 *   linked to a company the owner belongs to through InfoDot), the owner's confirmed email, confirmed outcomes with
 *   real counterparties (from conversations, once those exist), the page's age on Pulse.
 * - claimed: said by the business itself (registration number, website, phone). Shown as a claim, always.
 * - missing: not provided or not provable yet.
 *
 * Dormant path: if a future sign-in response carries `teams[].verification` ({status, verified_at, expires_at}),
 * refreshFromInfoDot() reads it, and only then can a page reach "infodot_verified". Until InfoDot sends it,
 * no page ever does.
 */
class VerificationService
{
    public const STATUS_LABELS = [
        'unverified' => 'Not verified',
        'claimed' => 'Details claimed, not verified',
        'infodot_member' => 'InfoDot team member',
        'infodot_verified' => 'Verified by InfoDot',
    ];

    /**
     * Ordered rungs, strongest first. Each: key, label, state (checked|claimed|missing), at (ISO time or null),
     * source (where the fact came from) and detail (a plain sentence).
     *
     * @return list<array{key: string, label: string, state: string, at: ?string, source: string, detail: string}>
     */
    public function ladder(PulseEntity $e): array
    {
        $rungs = [];
        $info = (array) $e->infodot_verification;
        $team = $this->linkedTeam($e);
        $received = (array) ($info['verification'] ?? []);

        if ($team !== null && $this->verificationIsCurrent($received)) {
            $rungs[] = $this->rung('infodot_verified', 'Verified by InfoDot', 'checked', $received['verified_at'] ?? null, 'infodot_redeem_verification',
                'InfoDot told Pulse this company is verified'.(isset($received['expires_at']) ? ' until '.Carbon::parse($received['expires_at'])->toFormattedDateString() : '').'.');
        }

        $rungs[] = $team !== null
            ? $this->rung('infodot_team', 'InfoDot team member', 'checked', $info['checked_at'] ?? null, 'infodot_redeem_teams',
                'The owner belongs to the InfoDot company "'.$team->name.'". This shows membership only; InfoDot has not verified the business to Pulse.')
            : $this->rung('infodot_team', 'InfoDot team member', 'missing', null, 'infodot_redeem_teams',
                'Link this page to an InfoDot company you belong to.');

        $owner = $e->members()->where('role', 'owner')->orderBy('id')->first()?->user;
        $rungs[] = $owner?->email_verified_at
            ? $this->rung('email_confirmed', 'Owner email confirmed', 'checked', $owner->email_verified_at->toIso8601String(), 'pulse_account', 'The owner confirmed their email address when signing in.')
            : $this->rung('email_confirmed', 'Owner email confirmed', 'missing', null, 'pulse_account', 'The owner has not confirmed an email address.');

        $claims = (array) $e->claims;
        $rungs[] = filled($e->registration_number)
            ? $this->rung('registration', 'Registration number', 'claimed', $claims['registration']['at'] ?? null, 'business_claim', 'The business says its registration number is '.$e->registration_number.'. Pulse has not checked it.')
            : $this->rung('registration', 'Registration number', 'missing', null, 'business_claim', 'No registration number given.');

        $rungs[] = filled($e->website)
            ? $this->rung('website', 'Website', 'claimed', $claims['website']['at'] ?? null, 'business_claim', 'The business says its website is '.$e->website.'. Pulse has not checked that it is theirs.')
            : $this->rung('website', 'Website', 'missing', null, 'business_claim', 'No website given.');

        $rungs[] = filled($e->public_phone)
            ? $this->rung('phone', 'Phone number', 'claimed', $claims['phone']['at'] ?? null, 'business_claim', 'The business gives a phone number. Pulse has not called it.')
            : $this->rung('phone', 'Phone number', 'missing', null, 'business_claim', 'No phone number given.');

        $stats = app(EntityService::class)->stats($e);
        $outcomes = (int) $stats['confirmed_outcomes'];
        $rungs[] = $outcomes > 0
            ? $this->rung('outcomes', 'Confirmed results', 'checked', $this->lastOutcomeAt($e), 'pulse_conversations',
                $outcomes.' result'.($outcomes === 1 ? '' : 's').' that both sides confirmed.')
            : $this->rung('outcomes', 'Confirmed results', 'missing', null, 'pulse_conversations', 'No result confirmed by both sides yet.');

        $days = (int) $e->created_at->diffInDays(now());
        $rungs[] = $this->rung('time_on_pulse', 'Time on Pulse', 'checked', $e->created_at->toIso8601String(), 'pulse_records',
            'On Pulse since '.$e->created_at->toFormattedDateString().' ('.$days.' day'.($days === 1 ? '' : 's').').');

        return $rungs;
    }

    /** @return array{status: string, label: string, checked: int, claimed: int} */
    public function summary(PulseEntity $e): array
    {
        $rungs = $this->ladder($e);

        return [
            'status' => $e->verification_status,
            'label' => self::STATUS_LABELS[$e->verification_status] ?? 'Not verified',
            'checked' => count(array_filter($rungs, fn ($r) => $r['state'] === 'checked')),
            'claimed' => count(array_filter($rungs, fn ($r) => $r['state'] === 'claimed')),
        ];
    }

    public function claimRegistration(User $by, PulseEntity $e, string $number): void
    {
        Gate::forUser($by)->authorize('update', $e);

        $number = (string) Sanitizer::line($number, 40);

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9\/ .\-]{2,38}[A-Za-z0-9]$/', $number)) {
            throw ValidationException::withMessages(['number' => 'Enter the registration number as it appears on your registration document.']);
        }

        $claims = (array) $e->claims;
        $claims['registration'] = ['value' => $number, 'at' => now()->toIso8601String()];

        $e->forceFill(['registration_number' => $number, 'claims' => $claims])->save();
        $this->recompute($e);

        Audit::record($by, 'entity.claim.registration', $e);
    }

    /** Links the page to an InfoDot company the owner belongs to. Membership only: InfoDot shares nothing more. */
    public function linkInfoDotCompany(User $by, PulseEntity $e, Team $team): void
    {
        Gate::forUser($by)->authorize('update', $e);

        if ($e->memberRole($by) !== 'owner') {
            throw ValidationException::withMessages(['team' => 'Only an owner can link the page to an InfoDot company.']);
        }

        $company = filled($team->infodot_team_uuid) ? InfoDotLink::team($by, $team->infodot_team_uuid) : null;

        if ($company === null || ! InfoDotLink::manages($by, $company)) {
            throw ValidationException::withMessages(['team' => 'You can link a company that you own or administer in InfoDot.']);
        }

        if (PulseEntity::withTrashed()->where('infodot_business_ref', $company->infodot_team_uuid)->where('id', '!=', $e->id)->exists()) {
            throw ValidationException::withMessages(['team' => 'Another page is already linked to that company.']);
        }

        $e->forceFill(['infodot_business_ref' => $company->infodot_team_uuid])->save();
        $this->refreshFromInfoDot($e);

        Audit::record($by, 'entity.infodot.link', $e, ['team_uuid' => $company->infodot_team_uuid]);
    }

    public function unlinkInfoDotCompany(User $by, PulseEntity $e): void
    {
        Gate::forUser($by)->authorize('update', $e);

        $e->forceFill(['infodot_business_ref' => null])->save();
        $this->refreshFromInfoDot($e);

        Audit::record($by, 'entity.infodot.unlink', $e);
    }

    /**
     * Re-reads what Pulse knows from InfoDot and sets the page's verification state from it. No network call: the
     * facts are the team link synced at sign-in and, when $identity (the sign-in response) is passed and carries a
     * `teams[].verification` object for the linked company, that object. Call it after every sign-in for the
     * person's pages (see refreshForUser).
     *
     * @param  array<string, mixed>|null  $identity  the redeem response
     */
    public function refreshFromInfoDot(PulseEntity $e, ?array $identity = null): void
    {
        $team = $this->linkedTeam($e);

        if ($team === null) {
            $e->forceFill([
                'infodot_verification' => null,
                'verification_source' => null,
                'verification_ref' => null,
                'verified_at' => null,
            ]);
        } else {
            $info = (array) $e->infodot_verification;
            $received = (array) ($info['verification'] ?? []);

            foreach ((array) ($identity['teams'] ?? []) as $entry) {
                if (is_array($entry) && ($entry['uuid'] ?? null) === $team->infodot_team_uuid) {
                    $received = is_array($entry['verification'] ?? null) ? $this->readVerification($entry['verification']) : [];
                }
            }

            $current = $this->verificationIsCurrent($received);

            $e->forceFill([
                'infodot_verification' => [
                    'team_uuid' => $team->infodot_team_uuid,
                    'team_name' => $team->name,
                    'checked_at' => now()->toIso8601String(),
                    'verification' => $received ?: null,
                ],
                'verification_source' => 'infodot',
                'verification_ref' => $team->infodot_team_uuid,
                'verified_at' => $current ? Carbon::parse($received['verified_at'])->toDateTimeString() : null,
            ]);
        }

        $e->forceFill(['verification_status' => $this->statusFor($e, $team)])->save();
    }

    /** Same as refreshFromInfoDot, with no new facts: recomputes the status after a claim changed. */
    public function recompute(PulseEntity $e): PulseEntity
    {
        $this->refreshFromInfoDot($e);

        return $e;
    }

    /** After a sign-in: refresh every page the person owns or administers. Returns the number refreshed. */
    public function refreshForUser(User $user, ?array $identity = null): int
    {
        $count = 0;

        PulseEntity::query()->whereIn('id', \App\Models\PulseEntityMember::query()->where('user_id', $user->id)->whereIn('role', ['owner', 'admin'])->select('entity_id'))
            ->each(function (PulseEntity $e) use ($identity, &$count) {
                $this->refreshFromInfoDot($e, $identity);
                $count++;
            });

        return $count;
    }

    // ---- internals ------------------------------------------------------------------------------------------

    /** The InfoDot company this page is linked to, but only while an owner or admin of the page still belongs to it. */
    private function linkedTeam(PulseEntity $e): ?Team
    {
        if (blank($e->infodot_business_ref)) {
            return null;
        }

        $team = Team::query()->where('infodot_team_uuid', $e->infodot_business_ref)->first();

        if ($team === null || $team->personal_team) {
            return null;
        }

        $people = $e->members()->whereIn('role', ['owner', 'admin'])->with('user')->get()->pluck('user')->filter();

        return $people->contains(fn (User $u) => $u->belongsToTeam($team)) ? $team : null;
    }

    private function statusFor(PulseEntity $e, ?Team $team): string
    {
        $received = (array) ($e->infodot_verification['verification'] ?? []);

        if ($team !== null && $this->verificationIsCurrent($received)) {
            return 'infodot_verified';
        }

        if ($team !== null) {
            return 'infodot_member';
        }

        return filled($e->registration_number) || filled($e->website) || filled($e->public_phone) ? 'claimed' : 'unverified';
    }

    /**
     * Keeps only the fields Pulse understands from a received verification object.
     *
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private function readVerification(array $v): array
    {
        return array_filter([
            'status' => isset($v['status']) ? (string) $v['status'] : null,
            'verified_at' => $this->time($v['verified_at'] ?? null),
            'expires_at' => $this->time($v['expires_at'] ?? null),
            'read_at' => now()->toIso8601String(),
        ], fn ($x) => $x !== null);
    }

    /** @param array<string, mixed> $received */
    private function verificationIsCurrent(array $received): bool
    {
        if (($received['status'] ?? null) !== 'verified' || empty($received['verified_at'])) {
            return false;
        }

        return empty($received['expires_at']) || Carbon::parse($received['expires_at'])->isFuture();
    }

    private function time(mixed $value): ?string
    {
        try {
            return $value ? Carbon::parse((string) $value)->toIso8601String() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function lastOutcomeAt(PulseEntity $e): ?string
    {
        $at = \Illuminate\Support\Facades\DB::table('pulse_conversations')->where('entity_id', $e->id)
            ->whereNotNull('outcome_initiator_at')->whereNotNull('outcome_recipient_at')->where('outcome', '!=', 'none')
            ->selectRaw('max(outcome_initiator_at) as a, max(outcome_recipient_at) as b')->first();

        $times = array_filter([$at->a ?? null, $at->b ?? null]);

        return $times ? Carbon::parse(max($times))->toIso8601String() : null;
    }

    /** @return array{key: string, label: string, state: string, at: ?string, source: string, detail: string} */
    private function rung(string $key, string $label, string $state, ?string $at, string $source, string $detail): array
    {
        return compact('key', 'label', 'state', 'at', 'source', 'detail');
    }
}
