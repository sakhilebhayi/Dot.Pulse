<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\Pulse\MediaController;
use App\Models\Community;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::post('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->middleware('throttle:10,1')
    ->name('ecosystem.auth');

// Links minted before the token moved into a POST body carried it in the URL. They are no longer honoured:
// the visitor is sent to the normal sign-in page and the token in the URL is never read.
Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'legacy']);

Route::get('/', fn () => view('welcome'));

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        $user = auth()->user();
        $profile = PulseProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['role' => 'customer', 'community_points' => 0],
        );

        return view('dashboard', [
            'totalPosts' => PulsePost::published()->visibleTo($user)->count(),
            'totalCommunities' => Community::count(),
            'myPoints' => $profile->community_points ?? 0,
        ]);
    })->name('dashboard');

    Route::get('/moderation', fn () => view('pulse.moderation'))->name('moderation');

    Route::get('/posts/{post}', fn (PulsePost $post) => view('pulse.post-show', ['post' => $post]))->name('posts.show');

    Route::get('/u/{profileUser}', fn (User $profileUser) => view('pulse.user-profile', ['profileUser' => $profileUser]))->name('users.show');

    Route::get('/c/{community:slug}', fn (Community $community) => view('pulse.community-show', ['community' => $community]))->name('communities.show');
});

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}

// --- media ---
// Images are read through this route only. Authorisation is decided per request in MediaService::canView
// (public images of live pages to anyone, message media to the people in the conversation).
Route::get('/media/{mediaFile}/{size?}', MediaController::class)
    ->whereNumber('mediaFile')
    ->where('size', 'thumb|medium|large')
    ->middleware('throttle:300,1')
    ->name('pulse.media');
// --- end media ---
// --- shell ---
// Component gallery for development, QA and screenshots. Registered only locally and under test.
if (app()->environment('local', 'testing')) {
    Route::view('/dev/kit', 'dev.kit')->name('dev.kit');
    Route::view('/dev/floor', 'dev.floor')->name('dev.floor');
}
// --- /shell ---
// --- lines ---
// The shell badge polls this every 30 seconds while the page is visible: three counts, three indexed queries.
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'throttle:120,1'])
    ->get('/api/pulse/pulse-check', 'App\\Http\\Controllers\\Pulse\\PulseCheckController')
    ->name('pulse.check');
// --- end lines ---
// --- discovery digest (D2) ---
// Signed link in the digest email: switches the digest off for the user in the URL. GET (click) and POST (a mail
// client's one-click List-Unsubscribe-Post). No sign-in needed; the signature is the proof.
Route::match(['get', 'post'], '/pulse/digest/unsubscribe/{user}', \App\Http\Controllers\Pulse\DigestUnsubscribeController::class)
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('pulse.digest.unsubscribe');
// --- end discovery digest (D2) ---
// --- business ---
// Business pages, offers, roles and needs (UI agent U2). Every page re-checks its policy; a page, offer, role or need
// a person may not see is a 404, never a 403, so the route does not confirm that it exists.
Route::get('/b/{entity:slug}', function (App\Models\PulseEntity $entity) {
    abort_unless(Illuminate\Support\Facades\Gate::allows('view', $entity), 404);

    return view('pulse.business.show', [
        'entity' => $entity,
        'data' => App\Livewire\Pulse\Business\Support\EntityView::data($entity, auth()->user()),
    ]);
})->name('entities.show');

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/business/new', fn () => view('pulse.business.create'))->name('entities.create');

    Route::get('/b/{entity:slug}/o/{offer:slug}', function (App\Models\PulseEntity $entity, App\Models\PulseOffer $offer) {
        abort_unless(Illuminate\Support\Facades\Gate::allows('view', $entity) && Illuminate\Support\Facades\Gate::allows('view', $offer), 404);

        return view('pulse.business.offer', ['entity' => $entity, 'offer' => $offer]);
    })->name('offers.show');

    Route::get('/b/{entity:slug}/r/{role:slug}', function (App\Models\PulseEntity $entity, App\Models\PulseRole $role) {
        // A closed or filled role still answers, so the people who applied can see how it ended.
        $ended = in_array($role->status, ['closed', 'filled'], true) && $role->moderation === 'approved' && $entity->isLive();
        abort_unless(Illuminate\Support\Facades\Gate::allows('view', $entity) && (Illuminate\Support\Facades\Gate::allows('view', $role) || $ended), 404);

        return view('pulse.business.role', ['entity' => $entity, 'role' => $role]);
    })->name('roles.show');

    $manage = function (App\Models\PulseEntity $entity, string $tab = 'overview') {
        $role = $entity->memberRole(auth()->user());
        abort_if($role === null, 404);

        $allowed = match ($tab) {
            'page', 'verify' => in_array($role, ['owner', 'admin'], true),
            'offers', 'roles' => in_array($role, ['owner', 'admin', 'editor'], true),
            'team' => in_array($role, ['owner', 'admin'], true),
            'lines' => App\Services\Pulse\Lines\Access::canRespondFor(auth()->user(), $entity),
            default => true,
        };
        abort_unless($allowed, 403);

        return view('pulse.business.manage', ['entity' => $entity, 'tab' => $tab, 'myRole' => $role]);
    };

    Route::get('/b/{entity:slug}/manage', $manage)->name('entities.manage');
    foreach (['page', 'offers', 'roles', 'team', 'lines', 'verify'] as $tab) {
        Route::get('/b/{entity:slug}/manage/'.$tab, $manage)->defaults('tab', $tab)->name('entities.manage.'.$tab);
    }

    Route::get('/need/new', fn () => view('pulse.need.create'))->name('needs.create');

    Route::get('/need/{need}', function (App\Models\PulseNeed $need) {
        return view('pulse.need.show', ['need' => App\Livewire\Pulse\Need\NeedPage::authorised($need)]);
    })->whereNumber('need')->name('needs.show');

    Route::get('/need/{need}/edit', function (App\Models\PulseNeed $need) {
        abort_unless($need->user_id === auth()->id(), 404);

        return view('pulse.need.edit', ['need' => $need]);
    })->whereNumber('need')->name('needs.edit');
});
// --- end business ---
