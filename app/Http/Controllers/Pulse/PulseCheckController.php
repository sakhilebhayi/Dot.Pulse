<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\PulseConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Counts for the shell badge: lines with something new, requests waiting for an answer, unread notifications.
 * Every tab polls this, so it is two indexed queries (the pages the person answers for, then all three counts in one
 * statement) and nothing else; no models are hydrated and the answer is never cached, so a badge is never stale.
 */
class PulseCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $pages = PulseConversation::responderEntityIds($user);

        $row = DB::query()
            ->selectSub(PulseConversation::query()->unreadFor($user, [PulseConversation::OPEN], $pages)->toBase()->selectRaw('count(*)'), 'lines_unread')
            ->selectSub(PulseConversation::query()->awaitingAnswerFrom($user, $pages)->toBase()->selectRaw('count(*)'), 'requests')
            ->selectSub(
                DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->getKey())->whereNull('read_at')->selectRaw('count(*)'),
                'notifications',
            )
            ->first();

        return response()->json([
            'lines_unread' => (int) $row->lines_unread,
            'requests' => (int) $row->requests,
            'notifications' => (int) $row->notifications,
        ])->header('Cache-Control', 'no-store');
    }
}
