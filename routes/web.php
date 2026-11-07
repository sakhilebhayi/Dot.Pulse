<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\Pulse\DigestUnsubscribeController;
use App\Http\Controllers\Pulse\MediaController;
use App\Models\Community;
use App\Models\PulseConversation;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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
Route::match(['get', 'post'], '/pulse/digest/unsubscribe/{user}', DigestUnsubscribeController::class)
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('pulse.digest.unsubscribe');
// --- end discovery digest (D2) ---
// --- lines-ui ---
// Lines: the inbox, one line's thread, and the side by side comparison of a quote request.
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/lines', fn () => view('livewire.pulse.lines.pages.index'))->name('lines');

    Route::get('/lines/quotes/{group}', function (string $group) {
        abort_unless(Str::isUuid($group), 404);

        return view('livewire.pulse.lines.pages.quotes', ['group' => $group]);
    })->name('lines.quotes');

    Route::get('/lines/{conversation}', function (PulseConversation $conversation) {
        abort_unless(Gate::allows('view', $conversation), 404);

        return view('livewire.pulse.lines.pages.show', ['conversation' => $conversation]);
    })->whereNumber('conversation')->name('lines.show');
});
// --- end lines-ui ---
