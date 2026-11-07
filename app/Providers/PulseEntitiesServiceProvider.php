<?php

namespace App\Providers;

use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseProfile;
use App\Models\PulseRole;
use App\Policies\PulseEntityPolicy;
use App\Policies\PulseNeedPolicy;
use App\Policies\PulseOfferPolicy;
use App\Policies\PulseRolePolicy;
use App\Services\Pulse\Entities\Observers\EntityObserver;
use App\Services\Pulse\Entities\Observers\ListingObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** Policies for business pages and listings, and the observers that keep the discovery index (pulse_signals) in step. */
class PulseEntitiesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(PulseEntity::class, PulseEntityPolicy::class);
        Gate::policy(PulseOffer::class, PulseOfferPolicy::class);
        Gate::policy(PulseRole::class, PulseRolePolicy::class);
        Gate::policy(PulseNeed::class, PulseNeedPolicy::class);

        PulseEntity::observe(EntityObserver::class);
        PulseOffer::observe(ListingObserver::class);
        PulseRole::observe(ListingObserver::class);
        PulseNeed::observe(ListingObserver::class);
        PulseProfile::observe(ListingObserver::class);
    }
}
