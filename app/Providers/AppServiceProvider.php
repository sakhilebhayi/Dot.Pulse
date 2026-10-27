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
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        Gate::policy(PulsePost::class, PulsePostPolicy::class);
        Gate::policy(PulseComment::class, PulseCommentPolicy::class);
        Gate::policy(Community::class, CommunityPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
    }
}
