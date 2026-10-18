<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InfoDot\IdentityClient;
use App\Services\InfoDot\InfoDotUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class EcosystemAuthController extends Controller
{
    public function handle(Request $request, IdentityClient $infoDot, ProvisionUserFromInfoDot $provision, SyncTeamsFromInfoDot $syncTeams): RedirectResponse
    {
        $token = $request->query('token');

        abort_unless(is_string($token) && $token !== '', 403);

        $user = $this->userFromLocalToken($token);

        // Production gives each Dot app its own database, so InfoDot's token is not
        // in ours: ask InfoDot to prove it.
        if (! $user && $infoDot->enabled()) {
            try {
                $identity = $infoDot->redeem($token);
            } catch (InfoDotUnavailable) {
                abort(503, 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.');
            }

            $user = $identity ? $provision($identity) : null;

            if ($user) {
                $syncTeams($user, $identity);
            }
        }

        abort_if(! $user, 403);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect($this->safeRedirect($request->query('redirect')));
    }

    /** A token minted in a database we share (the original design): found, checked, spent. */
    private function userFromLocalToken(string $token): ?User
    {
        $accessToken = PersonalAccessToken::findToken($token);

        if (
            ! $accessToken
            || ! $accessToken->can('ecosystem:read')
            || ($accessToken->expires_at && $accessToken->expires_at->isPast())
        ) {
            return null;
        }

        /** @var User|null $user */
        $user = $accessToken->tokenable;
        $accessToken->delete();

        return $user;
    }

    /**
     * A deep link names a page to open after sign-in. Only a path on this app is honoured:
     * a single leading slash, nothing that a browser could read as another host (//, \),
     * no traversal (..), no control characters. Anything else falls back to the dashboard,
     * so this can never be turned into an open redirect.
     */
    private function safeRedirect(mixed $redirect): string
    {
        $fallback = route('dashboard');

        if (! is_string($redirect) || $redirect === '' || strlen($redirect) > 2000 || $redirect[0] !== '/') {
            return $fallback;
        }

        // Check the decoded form too, so %2e%2e or %2F%2F get no further than the literal ones.
        foreach ([$redirect, rawurldecode($redirect)] as $form) {
            if (
                str_starts_with($form, '//')
                || str_contains($form, '\\')
                || str_contains($form, '..')
                || preg_match('/[\x00-\x1f\x7f]/', $form)
            ) {
                return $fallback;
            }
        }

        return $redirect;
    }
}
