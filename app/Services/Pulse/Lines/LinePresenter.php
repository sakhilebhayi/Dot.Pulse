<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseMessage;
use App\Models\PulseProfile;
use App\Models\User;

/**
 * What a viewer is allowed to see of a line. Every client facing read of a conversation goes through here: the
 * identity of an anonymous professional is replaced by an alias and an anonymous card for the page that invited
 * them (and for staff reviewing a report), and ids that would let the page look the person up are left out.
 */
class LinePresenter
{
    /**
     * @param  ?string  $snippet  latest message text, when the caller has it
     * @param  list<int>|null  $pages  the pages the viewer answers for, when the caller has them (an inbox page)
     * @return array<string, mixed>
     */
    public function present(User $viewer, PulseConversation $c, ?string $snippet = null, ?array $pages = null): array
    {
        $side = Access::side($viewer, $c, $pages);
        $hiddenFromViewer = $this->hiddenFrom($c, $side);

        $title = $c->context_title ?: $this->fallbackTitle($c);
        $declinedLabel = $c->status === PulseConversation::DECLINED && $side === 'initiator'
            ? (config('pulse.lines.decline_reasons.'.($c->decline_reason ?: 'not_now')) ?? 'Not now')
            : null;

        return [
            'id' => $c->id,
            'kind' => $c->kind,
            'status' => $this->statusFor($c, $side),
            'side' => $side,
            'title' => $title,
            'context' => [
                'type' => $c->context_type,
                'id' => $hiddenFromViewer ? null : $c->context_id,
                'title' => $title,
                'snapshot' => $c->context_snapshot ?? [],
            ],
            'card' => $c->card ?? [],
            'counterpart' => $this->counterpart($c, $side),
            'identity_hidden' => $hiddenFromViewer,
            // Structural facts the screens need so they never read columns off the model: who spoke last, the group a
            // quote request belongs to (the asking side only: the compare screen is theirs), and the viewer's own page.
            'last_message_side' => $c->last_message_side,
            'quote_group' => $side === 'initiator' ? $c->quote_group : null,
            'page' => $this->ownPage($c, $side),
            'snippet' => $snippet,
            'unread' => $this->unread($c, $side),
            'created_at' => $c->created_at,
            'last_message_at' => $c->last_message_at,
            'expires_at' => $c->status === PulseConversation::REQUESTED
                ? $c->created_at->copy()->addDays((int) config('pulse.lines.request_expiry_days', 14))
                : null,
            'outcome' => [
                'value' => $c->outcome,
                'confirmed' => $c->hasConfirmedOutcome(),
                'proposed_by_me' => $c->outcome !== 'none' && $c->outcome_side === $side,
                'awaiting_me' => $c->outcome !== 'none' && $c->outcome_side !== $side && ! $c->hasConfirmedOutcome(),
            ],
            'declined_label' => $declinedLabel,
            'closed_reason' => $c->closed_reason,
            'can' => [
                'send' => $side !== null && $c->status === PulseConversation::OPEN,
                'accept' => $side === 'recipient' && $c->status === PulseConversation::REQUESTED,
                'decline' => $side === 'recipient' && $c->status === PulseConversation::REQUESTED,
                'ignore' => $side === 'recipient' && $c->status === PulseConversation::REQUESTED && $c->ignored_at === null,
                'close' => $side !== null && in_array($c->status, [PulseConversation::REQUESTED, PulseConversation::OPEN], true)
                    && ($c->status === PulseConversation::OPEN || $side === 'initiator'),
                'reveal' => $c->identity_hidden && $c->recipient_id === $viewer->id && $c->status === PulseConversation::OPEN,
                'links' => $side !== null && ($side === 'recipient' || $c->first_response_at !== null),
            ],
            'guidance' => $this->guidance($c),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function thread(User $viewer, PulseConversation $c, ?int $afterId = null, int $limit = 200): array
    {
        $side = Access::side($viewer, $c);

        $messages = PulseMessage::query()
            ->where('pulse_conversation_id', $c->id)
            ->when(! $c->identity_hidden, fn ($q) => $q->with('author:id,name'))
            ->when($afterId, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $messages->map(fn (PulseMessage $m) => $this->message($viewer, $c, $m, $side))->values()->all();
    }

    /** @return array<string, mixed> */
    public function message(User $viewer, PulseConversation $c, PulseMessage $m, ?string $viewerSide): array
    {
        $mine = $m->user_id === $viewer->id;
        $messageSide = $m->side;
        $label = match (true) {
            $m->kind === 'system' => null,
            $mine => 'You',
            default => $this->label($c, $messageSide, $viewerSide),
        };

        $meta = $m->meta ?? [];
        // Only what the screen needs, never anything that names the author.
        $public = array_intersect_key($meta, array_flip(['card', 'event', 'quote_id', 'outcome']));

        return [
            'id' => $m->id,
            'kind' => $m->kind,
            'mine' => $mine,
            'side' => $messageSide,
            'from' => $label,
            'teammate' => ! $mine && ! $c->identity_hidden && $viewerSide !== null && $viewerSide === $messageSide && $m->kind !== 'system'
                ? $m->author?->name
                : null,
            'body' => $m->erased_at ? 'This message was deleted.' : $m->body,
            'meta' => $public,
            'media_id' => $m->erased_at ? null : $m->media_id,
            'created_at' => $m->created_at,
        ];
    }

    /**
     * The party on the other side from the viewer, as the viewer may know them.
     *
     * @return array<string, mixed>
     */
    public function counterpart(PulseConversation $c, ?string $viewerSide): array
    {
        $theirSide = $viewerSide === null ? 'recipient' : Access::otherSide($viewerSide);

        return $this->party($c, $theirSide, $viewerSide);
    }

    /** The display name of one side as seen by a viewer on `$viewerSide` (null for staff). */
    public function label(PulseConversation $c, ?string $partySide, ?string $viewerSide): string
    {
        if ($partySide === null) {
            return 'Someone';
        }

        $party = $this->party($c, $partySide, $viewerSide);

        return $party['alias'] ?? $party['name'];
    }

    /** The business page the viewer answers for on this line, if any. @return array{id: int, name: string, slug: string}|null */
    private function ownPage(PulseConversation $c, ?string $side): ?array
    {
        $entity = match ($side) {
            'recipient' => $c->entity,
            'initiator' => $c->initiatorEntity,
            default => null,
        };

        return $entity instanceof PulseEntity ? ['id' => (int) $entity->id, 'name' => $entity->name, 'slug' => $entity->slug] : null;
    }

    /** @var array<int, bool> */
    private array $linkable = [];

    /**
     * One query for a whole page of lines, so presenting twenty lines does not look up twenty profiles.
     *
     * @param  iterable<int|null>  $userIds
     */
    public function preloadLinkable(iterable $userIds): void
    {
        $ids = collect($userIds)->filter()->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => isset($this->linkable[$id]))->values()->all();

        if ($ids === []) {
            return;
        }

        $anonymous = PulseProfile::query()->whereIn('user_id', $ids)->where('discoverability', 'anonymous')->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        foreach ($ids as $id) {
            $this->linkable[$id] = ! in_array($id, $anonymous, true);
        }
    }

    /** False for someone whose page does not exist for others (an anonymous professional): no link is drawn. */
    private function linkable(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->linkable[$userId] ??= PulseProfile::query()->where('user_id', $userId)->value('discoverability') !== 'anonymous';
    }

    /** @return array<string, mixed> */
    private function party(PulseConversation $c, string $partySide, ?string $viewerSide): array
    {
        $entity = $partySide === 'initiator' ? $c->initiatorEntity : $c->entity;

        if ($entity instanceof PulseEntity) {
            return [
                'kind' => 'entity', 'hidden' => false, 'name' => $entity->name, 'slug' => $entity->slug,
                'entity_id' => $entity->id, 'logo_media_id' => $entity->logo_media_id,
            ];
        }

        if ($partySide === 'recipient' && $c->identity_hidden && $viewerSide !== 'recipient') {
            return [
                'kind' => 'anonymous', 'hidden' => true,
                'name' => 'Anonymous professional',
                'alias' => $this->alias($c),
                'card' => $c->context_snapshot ?? [],
            ];
        }

        $user = $partySide === 'initiator' ? $c->initiator : $c->recipient;

        return [
            'kind' => 'person', 'hidden' => false, 'name' => $user?->name ?? 'Someone',
            'user_id' => $user?->id, 'avatar_url' => $user?->profile_photo_url,
            'linkable' => $this->linkable($user?->id),
        ];
    }

    /** Stable for one line, different on every other line, so two lines cannot be matched to one person. */
    public function alias(PulseConversation $c): string
    {
        return 'Professional '.strtoupper(substr(hash_hmac('sha256', 'line:'.$c->id, (string) config('app.key')), 0, 4));
    }

    private function hiddenFrom(PulseConversation $c, ?string $side): bool
    {
        return $c->identity_hidden && $side !== 'recipient';
    }

    private function statusFor(PulseConversation $c, ?string $side): string
    {
        // The sender is never told a request was marked as spam.
        return $c->status === PulseConversation::SPAM && $side === 'initiator' ? PulseConversation::CLOSED : $c->status;
    }

    private function unread(PulseConversation $c, ?string $side): bool
    {
        if ($side === null || $c->last_message_at === null || $c->last_message_side === $side) {
            return false;
        }

        $readAt = $side === 'initiator' ? $c->initiator_read_at : $c->recipient_read_at;

        // Read marks are always set past the last message (see LineService::markRead), so equal means new.
        return $readAt === null || $c->last_message_at->gte($readAt);
    }

    private function fallbackTitle(PulseConversation $c): string
    {
        return match ($c->kind) {
            'quote' => 'Quote request',
            'application' => 'Application',
            'invite' => 'Invitation',
            default => 'Conversation',
        };
    }

    /** @return list<string> */
    private function guidance(PulseConversation $c): array
    {
        $work = in_array($c->kind, ['application', 'invite'], true) || $c->context_type === 'role';
        $trade = in_array($c->context_type, ['offer', 'need'], true) || $c->kind === 'quote' || $c->kind === 'enquiry';

        return match (true) {
            $work => array_values((array) config('pulse.safety.work', [])),
            $trade => array_values((array) config('pulse.safety.trade', [])),
            default => [],
        };
    }
}
