<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\PulseConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Counts for the shell badge: lines with something new, requests waiting for an answer, unread notifications.
 * Three indexed queries and nothing else; no models are hydrated.
 */
class PulseCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'lines_unread' => PulseConversation::query()->unreadFor($user, [PulseConversation::OPEN])->count(),
            'requests' => PulseConversation::query()->awaitingAnswerFrom($user)->count(),
            'notifications' => $user->unreadNotifications()->count(),
        ])->header('Cache-Control', 'no-store');
    }
}
