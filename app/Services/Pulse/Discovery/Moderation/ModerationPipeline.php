<?php

namespace App\Services\Pulse\Discovery\Moderation;

use App\Models\User;
use App\Services\Pulse\Discovery\Support\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * The one entry point other modules call before publishing text: offers, roles, needs, messages, comments.
 *
 *   $d = app(ModerationPipeline::class)->review($text, $author, ['kind' => 'offer', 'subject' => $offer]);
 *   $offer->moderation = $d->moderation();     // approved | held | removed
 *   $d->reason;                                // shown to the author when held or removed
 *
 * It is synchronous and needs no model key. Posts additionally get the optional model second opinion on a hold, in
 * the queued job (see AiModerationService), because that call can be slow and must not run inside a web request.
 */
class ModerationPipeline
{
    public function __construct(private readonly LocalRules $rules) {}

    /**
     * @param  array<string, mixed>  $ctx  see LocalRules; plus `subject` (a Model) to write an audit line
     */
    public function review(string $text, User $author, array $ctx = []): ModerationDecision
    {
        $subject = $ctx['subject'] ?? null;
        unset($ctx['subject']);

        $a = $this->rules->assess($text, $author, $ctx);
        $decision = new ModerationDecision($a['verdict'], $a['flags'], $a['reason'], $a['score']);

        if (! $decision->allowed()) {
            Audit::record($author, 'pulse.moderation.'.$decision->verdict, $subject instanceof Model ? $subject : null, [
                'kind' => $ctx['kind'] ?? 'text',
                'flags' => $decision->codes(),
            ]);
        }

        return $decision;
    }
}
