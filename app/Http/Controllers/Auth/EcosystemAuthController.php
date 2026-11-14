<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\User;
use App\Services\InfoDot\IdentityClient;
use App\Services\InfoDot\InfoDotUnavailable;
use App\Services\Pulse\Entities\VerificationService;
use App\Support\EntitlementRefusal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EcosystemAuthController extends Controller
{
    /** A GET (an old link with the token in the URL) is not honoured: the token is never read. */
    public function legacy(): RedirectResponse
    {
        return redirect()->to('/login');
    }

    /**
     * Sign-in from InfoDot. The token arrives in the POST body of a form InfoDot's launch page posts here, so it
     * is never in a URL (address bar, history, access logs, Referer). A refused sign-in lands on the plain login
     * page: the credential is not shown, echoed, flashed or logged. A GET with ?token= is no longer honoured
     * (the route sends it to the login page), and a token in the query string of a POST is ignored.
     */
    public function handle(Request $request): Response
    {
        if ($request->isMethod('post') && ! $this->postedFromInfoDot($request)) {
            return $this->refused();
        }

        try {
            return app()->call([$this, 'redeem'], ['request' => $request]);
        } catch (HttpExceptionInterface $e) {
            return $this->refused($e->getStatusCode() === 503);
        }
    }

    /**
     * Login CSRF: a form on another site could sign a visitor in as someone else (the attacker's own valid
     * token). A browser names the page that posted a cross-site form in Origin, so only InfoDot's launch page,
     * on this app's parent domain (tasks.infodot.co.za -> infodot.co.za), is accepted.
     */
    private function postedFromInfoDot(Request $request): bool
    {
        $origin = (string) $request->headers->get('Origin', '');
        $host = $origin !== '' && $origin !== 'null'
            ? parse_url($origin, PHP_URL_HOST)
            : parse_url((string) $request->headers->get('Referer', ''), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return in_array($request->headers->get('Sec-Fetch-Site'), ['same-origin', 'same-site'], true);
        }

        $host = strtolower($host);

        // When this app knows InfoDot's address, exactly that origin is accepted, not a sibling subdomain.
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return $host === strtolower($infodot);
        }

        // An app without that setting falls back to its own parent domain.
        $self = strtolower($request->getHost());
        $parent = str_contains($self, '.') ? substr($self, strpos($self, '.') + 1) : $self;

        return $host === $self || $host === $parent || str_ends_with($host, '.'.$parent);
    }

    private function refused(bool $unavailable = false): RedirectResponse
    {
        return redirect('/login', 303)->with('status', $unavailable
            ? 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.'
            : 'We could not sign you in through InfoDot. Please open this app again from InfoDot.');
    }

    public function redeem(Request $request, IdentityClient $infoDot, ProvisionUserFromInfoDot $provision, SyncTeamsFromInfoDot $syncTeams): Response
    {
        $token = $request->post('token');

        abort_unless(is_string($token) && $token !== '', 403);

        $user = $this->userFromLocalToken($token);
        $identity = null;

        // Production gives each Dot app its own database, so InfoDot's token is not
        // in ours: ask InfoDot to prove it.
        if (! $user && $infoDot->enabled()) {
            try {
                $identity = $infoDot->redeem($token);
            } catch (InfoDotUnavailable) {
                abort(503, 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.');
            }

            // R-50: InfoDot marks each company with whether it holds this platform. When it says none does, nobody
            // is signed in and nothing is created: the person gets the plain page that says so.
            if ($identity && ($refusal = $this->nothingEntitled($identity)) !== null) {
                return EntitlementRefusal::response($refusal['reason'], $refusal['company']);
            }

            $user = $identity ? $provision($identity) : null;

            if ($user) {
                $syncTeams($user, $identity);

                // A page linked to an InfoDot company re-reads what InfoDot said about it. Never blocks sign-in.
                try {
                    app(VerificationService::class)->refreshForUser($user, $identity);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        abort_if(! $user, 403);

        Auth::login($user);
        $request->session()->regenerate();

        // R-50: what InfoDot said about the person's personal workspace for this platform, kept with the sign-in so
        // the local personal team is not a way round the company's entitlement. No key: nothing is remembered.
        $personal = collect($identity['teams'] ?? [])->first(fn ($t) => is_array($t) && ($t['personal'] ?? false) === true);

        if (is_array($personal) && array_key_exists('entitled', $personal)) {
            $request->session()->put(EnsureCompanyEntitled::PERSONAL, $personal['entitled'] === true);
        } else {
            $request->session()->forget(EnsureCompanyEntitled::PERSONAL);
        }

        return redirect($this->safeRedirect($request->post('redirect')));
    }

    /**
     * The reason and company to name when InfoDot answered and no company in the answer holds this platform. Null
     * when at least one does, or when any company comes without the `entitled` key (an InfoDot from before R-50,
     * where absence is not a refusal).
     *
     * @param  array<string, mixed>  $identity
     * @return array{reason: string, company: ?string}|null
     */
    private function nothingEntitled(array $identity): ?array
    {
        $teams = collect($identity['teams'] ?? [])->filter(fn ($t) => is_array($t))->values();

        if ($teams->isEmpty() || $teams->contains(fn ($t) => ! array_key_exists('entitled', $t) || $t['entitled'] === true)) {
            return null;
        }

        $active = $identity['current_team_uuid'] ?? null;
        $about = $teams->first(fn ($t) => $active !== null && ($t['uuid'] ?? null) === $active && ! ($t['personal'] ?? false))
            ?? $teams->first(fn ($t) => ! ($t['personal'] ?? false))
            ?? $teams->first();

        return [
            'reason' => is_string($about['access'] ?? null) ? $about['access'] : '',
            'company' => ($about['personal'] ?? false) || ! is_string($about['name'] ?? null) ? null : $about['name'],
        ];
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
     * no traversal (..), no control characters. Anything else falls back to the Floor (a first visit is sent on to the welcome flow by the pulse.onboarded middleware),
     * so this can never be turned into an open redirect.
     */
    private function safeRedirect(mixed $redirect): string
    {
        $fallback = route('floor');

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
