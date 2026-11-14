<?php

namespace App\Providers;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\Community;
use App\Models\PulseComment;
use App\Models\PulsePost;
use App\Models\User;
use App\Policies\CommunityPolicy;
use App\Policies\PulseCommentPolicy;
use App\Policies\PulsePostPolicy;
use App\Policies\UserPolicy;
use App\Services\InfoDot\FakeIdentityClient;
use App\Services\InfoDot\IdentityClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Browser end-to-end runs only (tests/e2e/pulse): a fake InfoDot redeem, never outside local or testing.
        if (env('PULSE_E2E_FAKE_INFODOT') === '1' && $this->app->environment('local', 'testing')) {
            $this->app->bind(IdentityClient::class, FakeIdentityClient::class);
        }

        // R-50: Livewire's file upload route is not on a page's route group, so it carries the check itself.
        if (! config('livewire.temporary_file_upload.middleware')) {
            config(['livewire.temporary_file_upload.middleware' => ['throttle:60,1', EnsureCompanyEntitled::class]]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // R-50: Livewire updates from a page in the signed-in area carry that page's entitlement check with them.
        Livewire::addPersistentMiddleware([EnsureCompanyEntitled::class]);

        Gate::policy(PulsePost::class, PulsePostPolicy::class);
        Gate::policy(PulseComment::class, PulseCommentPolicy::class);
        Gate::policy(Community::class, CommunityPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
    }
}
