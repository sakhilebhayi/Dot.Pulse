<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\EntitlementClient;
use App\Support\EntitlementRefusal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in area honours the company's entitlement (R-50).
 *
 * InfoDot decides whether a company holds Dot.Pulse. A person who is already signed in must stop working when that
 * ends, so each request in the signed-in area asks (through a ten-minute cache) whether the company whose Pulse is
 * being used still holds the platform. A clear "no" answers with the plain refusal page and HTTP 403: nothing is
 * read from or written to Pulse. Anything else, including every kind of failure, lets the request through
 * exactly as before.
 *
 * It sits on the signed-in route group (routes/web.php) and on Jetstream's own pages (config/jetstream.php), and is
 * a Livewire persistent middleware, so component updates from those pages are checked too. It is not on sign-in,
 * sign-out, the handoff receiver, health checks, or the public pages (a business page, the front page, signed
 * links): nothing a signed-out person can reach is ever checked. It also guards the file
 * upload route and the broadcasting auth route, which the Livewire persistent list does not reach.
 */
class EnsureCompanyEntitled
{
    /** Routes inside the checked groups that must keep working for a person whose company is refused. */
    private const EXEMPT = [
        'current-team.update', // switching to another company
        'profile.show',        // the person's own account
        'terms.show', 'policy.show', 'team-invitations.accept',
        'me.export',           // a person can always download what Pulse holds about them (POPIA); owner to confirm
    ];

    /** Session key: redeem's `entitled` for the person's personal workspace, remembered at sign-in (absent: not set). */
    public const PERSONAL = 'infodot.personal_entitled';

    public function __construct(private readonly EntitlementClient $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlements->enabled() || $request->routeIs(...self::EXEMPT)) {
            return $next($request);
        }

        // Data-subject rights are never blocked by a commercial access state: the person's own export and erasure.
        if ($request->routeIs('me.privacy') && $this->onlyDataRights()) {
            return $next($request);
        }

        $user = $request->user() ?? Auth::guard('web')->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $team = $this->company($request, $user);

        // A personal workspace, or a team InfoDot does not know: the read endpoint is never asked. What InfoDot said
        // about the person's personal workspace at sign-in decides: exactly false is a refusal (individuals hold
        // the free core only); true, or nothing remembered, carries on.
        if (! $team) {
            return $next($request);
        }

        if ($team->personal_team || blank($team->infodot_team_uuid)) {
            if (! $request->hasSession() || $request->session()->get(self::PERSONAL) !== false) {
                return $next($request);
            }

            return $this->refuse($request, fn () => EntitlementRefusal::response(
                'company_required',
                null,
                $user->allTeams()->reject(fn ($other) => $other->id === $team->id || $other->personal_team || blank($other->infodot_team_uuid)),
            ));
        }

        $answer = $this->entitlements->forCompany((string) $team->infodot_team_uuid);

        if ($answer === null || $answer['entitled'] === true) {
            return $next($request);
        }

        return $this->refuse($request, fn () => EntitlementRefusal::response(
            $answer['status'],
            $team->name,
            $user->allTeams()->reject(fn ($other) => $other->id === $team->id),
        ));
    }

    /** The privacy page's Livewire methods that belong to export and erasure (and their step-up code), nothing else. */
    private const RIGHTS_CALLS = ['startExport', 'cancelExport', 'export', 'startErase', 'cancelErase', 'erase', 'sendCode', 'codeRequired'];

    /** The only properties a data-rights update may set: the typed confirmation and the emailed code. */
    private const RIGHTS_PROPS = ['confirmation', 'code'];

    /**
     * On the privacy page: a plain page load is allowed (it is where the person reads and uses their rights). A
     * Livewire update is allowed only when every component is the privacy component and it does nothing but export
     * or erase steps. Withdrawing a setting, unblocking, unmuting and any other component's action stay refused. The
     * existing confirmations (typed phrase, emailed code, sole-owner check) run inside those methods, unchanged.
     */
    private function onlyDataRights(): bool
    {
        $live = request();

        if (! $live->headers->has('X-Livewire')) {
            return true;
        }

        $components = $live->input('components');

        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = is_array($component) ? json_decode((string) ($component['snapshot'] ?? ''), true) : null;

            if (! is_array($snapshot) || ($snapshot['memo']['name'] ?? null) !== 'pulse.me.privacy') {
                return false;
            }

            foreach ((array) ($component['calls'] ?? []) as $call) {
                if (! is_array($call) || ! in_array($call['method'] ?? null, self::RIGHTS_CALLS, true)) {
                    return false;
                }
            }

            foreach (array_keys((array) ($component['updates'] ?? [])) as $property) {
                if (! in_array($property, self::RIGHTS_PROPS, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Livewire throws away any non-redirect response a persistent middleware returns, so a refusal page would let a
     * component update run. On a Livewire update (and on any JSON call: an upload, a broadcasting auth) the refusal must abort: nothing runs and nothing comes back.
     */
    private function refuse(Request $request, Closure $page): Response
    {
        if ($request->headers->has('X-Livewire') || request()->headers->has('X-Livewire') || $request->expectsJson()) {
            abort(403);
        }

        return $page();
    }

    /** The company whose data this request opens: the one in the address when the person belongs to it, else their current one. */
    private function company(Request $request, User $user): ?Team
    {
        $named = $request->route('team');

        if (is_scalar($named)) {
            $named = $user->allTeams()->firstWhere('id', (int) $named);
        }

        if ($named instanceof Team && $user->belongsToTeam($named)) {
            return $named;
        }

        $current = $user->currentTeam;

        return $current instanceof Team ? $current : null;
    }
}
