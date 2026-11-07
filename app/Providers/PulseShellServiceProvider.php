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
}
