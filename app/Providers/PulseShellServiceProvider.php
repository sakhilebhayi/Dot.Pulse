<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Owned by the shell agent (R-41). Loads the shell helpers (pulse_route and friends) and
 * leaves room for the shell's view composers. Nothing here touches the domain.
 */
class PulseShellServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        require_once app_path('Support/pulse_helpers.php');
    }

    /**
     * The stock Jetstream profile page is replaced by the Floor's own: /user/profile now sends people to Me, and the
     * sign-in and device settings that matter live under /me/account. Jetstream registers the route and its name (so every
     * old link still resolves); the controller behind it is swapped in the container, which does not depend on route order.
     */
    public function boot(): void
    {
        $this->app->bind(\Laravel\Jetstream\Http\Controllers\Livewire\UserProfileController::class, \App\Http\Controllers\Pulse\ProfileRedirectController::class);
    }
}
