<?php

namespace App\Services\Pulse\Operator;

use App\Models\PulseComment;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseMessage;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulsePost;
use App\Models\PulseReview;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What a moderator sees of a reported thing: a plain label, the words that were reported, and who is responsible.
 * The identity of an anonymous professional is never shown here, even to a moderator: a hidden recipient on a line
 * is "the professional", a hidden talent signal's owner is "an anonymous professional".
 */
final class ReportedSubject
{
    /**
     * @return array{kind: string, title: ?string, body: ?string, by: ?string, where: ?string}
     */
    public static function describe(?Model $m): array
    {
        if ($m === null) {
            return ['kind' => 'Something', 'title' => null, 'body' => null, 'by' => null, 'where' => 'It has been deleted already.'];
        }

        return match (true) {
            $m instanceof PulsePost => ['kind' => 'Post', 'title' => $m->title, 'body' => $m->body, 'by' => $m->author?->name, 'where' => self::stateOf($m->status)],
            $m instanceof PulseComment => ['kind' => 'Comment', 'title' => null, 'body' => $m->body, 'by' => $m->author?->name, 'where' => $m->trashed() ? 'Already removed.' : null],
            $m instanceof User => ['kind' => 'Profile', 'title' => $m->name, 'body' => $m->pulseProfile?->headline, 'by' => null, 'where' => null],
            $m instanceof PulseEntity => ['kind' => 'Business page', 'title' => $m->name, 'body' => self::join([$m->tagline, $m->about]), 'by' => $m->creator?->name, 'where' => self::stateOf($m->moderation)],
            $m instanceof PulseOffer => ['kind' => 'Offer', 'title' => $m->title, 'body' => self::join([$m->summary, $m->description]), 'by' => $m->creator?->name, 'where' => $m->entity?->name],
            $m instanceof PulseRole => ['kind' => 'Role', 'title' => $m->title, 'body' => self::join([$m->summary, $m->description]), 'by' => $m->creator?->name, 'where' => $m->entity?->name],
            $m instanceof PulseNeed => ['kind' => 'Need', 'title' => $m->title, 'body' => self::join([$m->details, $m->budget_text]), 'by' => $m->user?->name, 'where' => null],
            $m instanceof PulseSignal => ['kind' => 'Listing', 'title' => $m->title, 'body' => $m->excerpt, 'by' => $m->anonymous ? 'an anonymous professional' : $m->owner?->name, 'where' => null],
            $m instanceof PulseMessage => self::message($m),
            $m instanceof PulseConversation => self::conversation($m),
            $m instanceof PulseReview => ['kind' => 'Review', 'title' => str_repeat('★', (int) $m->rating).' ('.(int) $m->rating.' of 5)', 'body' => $m->body, 'by' => $m->pseudonymous ? 'a reviewer who chose to stay unnamed' : $m->author?->name, 'where' => 'Moderation: '.$m->moderation],
            default => ['kind' => class_basename($m), 'title' => null, 'body' => null, 'by' => null, 'where' => null],
        };
    }

    /** @return array{kind: string, title: ?string, body: ?string, by: ?string, where: ?string} */
    private static function message(PulseMessage $m): array
    {
        $c = $m->conversation;
        $hidden = $c?->identity_hidden && $m->side === 'recipient';

        return [
            'kind' => 'Message',
            'title' => $c?->context_title ? 'In a line about '.$c->context_title : null,
            'body' => $m->erased_at ? 'The writer erased this message.' : $m->body,
            'by' => $hidden ? 'the professional (kept anonymous)' : $m->author?->name,
            'where' => null,
        ];
    }

    /** @return array{kind: string, title: ?string, body: ?string, by: ?string, where: ?string} */
    private static function conversation(PulseConversation $c): array
    {
        $card = (array) $c->card;

        return [
            'kind' => 'Request',
            'title' => $c->context_title ? 'About '.$c->context_title : ucfirst($c->kind).' request',
            'body' => self::join([$card['what'] ?? null, $card['when_or_qty'] ?? null, $card['where'] ?? null, $card['budget_band'] ?? null], ', '),
            'by' => $c->initiator?->name,
            'where' => 'Line status: '.$c->status,
        ];
    }

    private static function stateOf(?string $state): ?string
    {
        return $state ? ucfirst(str_replace('_', ' ', $state)) : null;
    }

    /** @param  list<?string>  $parts */
    private static function join(array $parts, string $glue = "\n\n"): ?string
    {
        $text = implode($glue, array_filter($parts, fn ($p) => is_string($p) && trim($p) !== ''));

        return $text === '' ? null : Str::limit($text, 900);
    }
}
