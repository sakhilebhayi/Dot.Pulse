<?php

namespace App\Http\Controllers\Pulse;

use Illuminate\Http\RedirectResponse;

/** The old Jetstream profile page: people land on their Floor profile instead. */
class ProfileRedirectController
{
    public function show(): RedirectResponse
    {
        return redirect()->route('me.profile');
    }
}
