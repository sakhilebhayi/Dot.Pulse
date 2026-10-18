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
        //
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
