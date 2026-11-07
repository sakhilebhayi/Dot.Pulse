<?php

namespace App\Livewire\Pulse\Me\Concerns;

use App\Services\Pulse\Lines\NotificationPresenter;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One wording for every notification: Pulse ones (data carries a `category`) go through NotificationPresenter, the
 * older community and follow ones keep their original sentences.
 */
trait PresentsNotifications
{
    /** @return array{lead: string, text: string, url: ?string, category: string} */
    public function presentNotification(DatabaseNotification $notification): array
    {
        $data = (array) $notification->data;

        if (NotificationPresenter::handles($data)) {
            return NotificationPresenter::present($notification);
        }

        $row = match ($data['type'] ?? 'new_follower') {
            'community_join_requested' => [
                'lead' => $data['requester_name'] ?? 'Someone',
                'text' => ' asked to join '.($data['community_name'] ?? 'a community'),
                'url' => isset($data['community_slug']) ? route('communities.show', $data['community_slug']) : null,
            ],
            'community_join_decision' => ($data['approved'] ?? false)
                ? [
                    'lead' => 'Your request to join '.($data['community_name'] ?? 'a community').' was approved',
                    'text' => '',
                    'url' => isset($data['community_slug']) ? route('communities.show', $data['community_slug']) : null,
                ]
                : [
                    'lead' => 'Your request to join '.($data['community_name'] ?? 'a community').' was not accepted',
                    'text' => '',
                    'url' => null,
                ],
            'report_outcome' => [
                'lead' => 'Your report was reviewed',
                'text' => ($data['actioned'] ?? false) ? ': the content was removed.' : ': no action was needed.',
                'url' => null,
            ],
            'content_removed' => [
                'lead' => 'A '.($data['content'] ?? 'post').' of yours was removed by a moderator',
                'text' => '',
                'url' => null,
            ],
            default => [
                'lead' => $data['follower_name'] ?? 'Someone',
                'text' => ' started following you',
                'url' => isset($data['follower_id']) ? route('users.show', $data['follower_id']) : null,
            ],
        };

        return $row + ['category' => 'community'];
    }

    /** Only same-site links are followed from a notification. */
    protected function safeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // A backslash or a control character can turn "/\host" or "/<tab>/host" into another site in a browser.
        if (preg_match('/[\\\\\x00-\x20\x7f]/', $url)) {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        return parse_url($url, PHP_URL_HOST) === parse_url(url('/'), PHP_URL_HOST) ? $url : null;
    }
}
