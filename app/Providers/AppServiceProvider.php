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

        // --- entities ---
        Gate::policy(\App\Models\PulseEntity::class, \App\Policies\PulseEntityPolicy::class);
        Gate::policy(\App\Models\PulseOffer::class, \App\Policies\PulseOfferPolicy::class);
        Gate::policy(\App\Models\PulseRole::class, \App\Policies\PulseRolePolicy::class);
        Gate::policy(\App\Models\PulseNeed::class, \App\Policies\PulseNeedPolicy::class);

        // The discovery index follows its subjects: any write to a listing or profile re-syncs its pulse_signals row.
        \App\Models\PulseEntity::observe(\App\Services\Pulse\Entities\Observers\EntityObserver::class);
        \App\Models\PulseOffer::observe(\App\Services\Pulse\Entities\Observers\ListingObserver::class);
        \App\Models\PulseRole::observe(\App\Services\Pulse\Entities\Observers\ListingObserver::class);
        \App\Models\PulseNeed::observe(\App\Services\Pulse\Entities\Observers\ListingObserver::class);
        \App\Models\PulseProfile::observe(\App\Services\Pulse\Entities\Observers\ListingObserver::class);
        // --- end entities ---
    }
}
