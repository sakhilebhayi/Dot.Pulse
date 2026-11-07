<?php

namespace App\Providers;

use App\Models\Community;
use App\Models\PulseComment;
use App\Models\PulsePost;
use App\Models\User;
use App\Policies\CommunityPolicy;
use App\Policies\PulseCommentPolicy;
use App\Policies\PulsePostPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Browser end-to-end runs only (tests/e2e/pulse): a fake InfoDot redeem, never outside local or testing.
        if (env('PULSE_E2E_FAKE_INFODOT') === '1' && $this->app->environment('local', 'testing')) {
            $this->app->bind(\App\Services\InfoDot\IdentityClient::class, \App\Services\InfoDot\FakeIdentityClient::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(PulsePost::class, PulsePostPolicy::class);
        Gate::policy(PulseComment::class, PulseCommentPolicy::class);
        Gate::policy(Community::class, CommunityPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
    }
}
