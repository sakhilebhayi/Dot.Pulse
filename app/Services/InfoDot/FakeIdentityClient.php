<?php

namespace App\Services\InfoDot;

/**
 * A stand-in for InfoDot's redeem endpoint for the browser end-to-end run (tests/e2e/pulse). A browser run cannot
 * use Http::fake, so the runner sets PULSE_E2E_FAKE_INFODOT=1 and AppServiceProvider binds this class, and only
 * under the local or testing environment. It is never bound in production: the provider checks the environment first.
 *
 * Tokens: `e2e-valid:<email>:<name>[:company]` redeems to that person (with an InfoDot company when `company` is
 * given); `e2e-down` behaves like an InfoDot outage; anything else is refused like an unknown token.
 */
class FakeIdentityClient extends IdentityClient
{
    public function enabled(): bool
    {
        return true;
    }

    public function redeem(string $token): ?array
    {
        if ($token === 'e2e-down') {
            throw new InfoDotUnavailable('InfoDot is not available.');
        }

        if (! str_starts_with($token, 'e2e-valid:')) {
            return null;
        }

        [, $email, $name, $company] = array_pad(explode(':', $token, 4), 4, null);

        $teams = [['id' => 1, 'uuid' => null, 'name' => $name."'s Team", 'personal' => true, 'role' => 'owner']];

        if ($company !== null) {
            $teams[] = ['id' => 2, 'uuid' => 'e2e00000-0000-4000-8000-000000000001', 'name' => 'Brightwater Foods (Pty) Ltd', 'personal' => false, 'role' => 'owner'];
        }

        return [
            'user' => ['id' => 9001, 'name' => $name, 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
        ];
    }
}
