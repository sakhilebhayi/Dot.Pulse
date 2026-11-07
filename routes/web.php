<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\Pulse\DigestUnsubscribeController;
use App\Http\Controllers\Pulse\MediaController;
use App\Models\Community;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\PulseSignal;
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

// Signed-in people land on the Floor; everyone else sees the front page.
Route::get('/', fn () => auth()->check() ? redirect()->route('floor') : view('welcome'));

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    // The Floor replaced the dashboard. The old community feed lives on at /community (see the floor block).
    Route::get('/dashboard', fn () => redirect()->route('floor'))->name('dashboard');

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
Route::match(['get', 'post'], '/pulse/digest/unsubscribe/{user}', DigestUnsubscribeController::class)
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('pulse.digest.unsubscribe');
// --- end discovery digest (D2) ---
// --- operator ---
// Platform moderators only. The component checks again on every request it serves.
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/operator', function () {
        abort_unless(auth()->user()->isPlatformModerator(), 403);

        return view('pulse.operator');
    })->name('operator');
});
// --- end operator ---
// --- floor ---
// The Floor and everything that hangs off it: the four mode pages, a beat in full, search, saved, doors.
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    foreach (['floor' => 'all', 'trade' => 'trade', 'work' => 'work', 'local' => 'local', 'learn' => 'learn'] as $name => $mode) {
        Route::get('/'.$name, fn () => view('pages.pulse.floor.floor', ['mode' => $mode]))->name($name);
    }

    Route::get('/s/{signal}', fn (PulseSignal $signal) => view('pages.pulse.floor.detail', ['signal' => $signal]))
        ->whereNumber('signal')->name('signals.show');
    Route::get('/search', fn () => view('pages.pulse.floor.search'))->name('search');
    Route::get('/saved', fn () => view('pages.pulse.floor.saved'))->name('saved');
    Route::get('/door', fn () => view('pages.pulse.floor.door'))->name('door');

    // The community feed that used to be the dashboard: posts, communities and points.
    Route::get('/community', function () {
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
    })->name('community.feed');
});
// --- end floor ---
