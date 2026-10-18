<?php

namespace App\Actions\Ecosystem;

use App\Models\PulseProfile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns an InfoDot identity (already proven by the redeem endpoint) into a local user.
 *
 * Matches by verified email. An existing account whose email was never verified
 * here is taken over safely: its password is replaced, so whoever pre-registered
 * the address cannot keep using it. A person InfoDot vouches for but who has no
 * account here gets the same state as one created by CreateNewUser (random
 * password, personal team) plus the PulseProfile the dashboard would create on
 * first visit, so they can post immediately.
 *
 * Platform roles (PulseProfile.role: moderator, admin) are local decisions. They
 * are never read from the InfoDot payload, never granted here, and an existing
 * profile is never rewritten: firstOrCreate only supplies defaults for a new row.
 */
class ProvisionUserFromInfoDot
{
    /**
     * @param  array<string, mixed>  $identity
     */
    public function __invoke(array $identity): ?User
    {
        $email = Str::lower(trim((string) ($identity['user']['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ($identity['user']['email_verified'] ?? false) !== true) {
            return null;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user) {
            if ($user->email_verified_at === null) {
                $user->forceFill(['password' => Hash::make(Str::random(48)), 'email_verified_at' => now()])->save();
                Log::notice('Unverified local account adopted by InfoDot sign-in; password replaced.', ['user_id' => $user->id]);
            }

            $this->ensureProfile($user);

            return $user;
        }

        if (! config('ecosystem.infodot.provision', true)) {
            return null;
        }

        return DB::transaction(function () use ($identity, $email) {
            $name = trim((string) ($identity['user']['name'] ?? '')) ?: Str::before($email, '@');

            $user = new User(['name' => Str::limit($name, 255, ''), 'email' => $email, 'password' => Hash::make(Str::random(48))]);
            $user->forceFill(['email_verified_at' => now()])->save();

            // Same as App\Actions\Fortify\CreateNewUser::createTeam().
            $user->ownedTeams()->save(Team::forceCreate([
                'user_id' => $user->id,
                'name' => explode(' ', $user->name, 2)[0]."'s Team",
                'personal_team' => true,
            ]));

            $this->ensureProfile($user);

            return $user;
        });
    }

    /** Same defaults as the dashboard route and the profile form; an existing row (and its role) is left alone. */
    private function ensureProfile(User $user): void
    {
        PulseProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['role' => 'customer', 'community_points' => 0],
        );
    }
}
