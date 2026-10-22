<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Models\Community;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\User;
use Illuminate\Support\Facades\Route;

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
