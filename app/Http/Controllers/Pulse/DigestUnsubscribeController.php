<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\PulseNotificationPref;
use App\Models\User;
use App\Services\Pulse\Discovery\Support\Audit;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * One-click unsubscribe for the digest. The route is signed (the link in the email), accepts GET (the person
 * clicks) and POST (a mail client's one-click List-Unsubscribe-Post), needs no sign-in, and only ever switches the
 * digest off for the user in the signed URL. Other notifications are untouched.
 */
class DigestUnsubscribeController extends Controller
{
    public function __invoke(User $user): Response
    {
        $prefs = PulseNotificationPref::firstOrNew(['user_id' => $user->id]);
        $prefs->digest = false;
        $prefs->save();

        Audit::record($user, 'pulse.digest.unsubscribed');

        $manage = Route::has('me.notifications') ? route('me.notifications') : url('/');

        return response()->view('pulse.digest-unsubscribed', ['manage' => $manage]);
    }
}
