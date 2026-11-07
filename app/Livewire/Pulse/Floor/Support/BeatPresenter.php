<?php

namespace App\Livewire\Pulse\Floor\Support;

use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseRole;
use App\Models\User;
use App\Services\Pulse\Discovery\Beat;
use App\Services\Pulse\Lines\Access;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Turns a Beat into the plain array the Floor views draw from. Everything the screen needs is decided here, on the
 * server: what the Knock button targets, the words for a refused knock, the price or close date in the corner, the
 * verification words. Nothing in the array names a person behind an anonymous beat.
 *
 * The array is held in a locked Livewire property, so the client can read it but not change it.
 */
final class BeatPresenter
{
    /** Kinds that start a conversation. Asks, news and events are opened and answered on their own page. */
    private const KNOCKABLE = ['offer', 'need', 'role', 'talent', 'door', 'business', 'collab'];

    /** @var array<int, int|null> viewer id => employer page id, looked up once per presenter */
    private array $employers = [];

    /**
     * @return array<string, mixed>
     */
    public function present(Beat $beat, User $viewer): array
    {
        $row = $beat->toArray();
        $signal = $beat->signal;

        // The array rides in the Livewire snapshot (down on every render, up on every action), so it carries only what
        // the views read: the pre-check (can_knock), topics, the raw timestamp, the subject type and the beat's own url
        // are decided or shown elsewhere and are left out.
        unset($row['can_knock'], $row['topics'], $row['published_at'], $row['subject_type'], $row['url']);

        $row['when'] = self::ago($beat->published_at);
        $row['expires_at'] = $signal->expires_at?->toIso8601String();
        $row['aside'] = $this->aside($beat);
        $row['verification'] = $this->verification($beat);
        $row['knock'] = null;
        $row['knock_note'] = null;
        $row['knock_href'] = null;

        if (! in_array($beat->kind, self::KNOCKABLE, true)) {
            return $row;
        }

        if (! ($beat->can_knock['allowed'] ?? false)) {
            $row['knock_note'] = $beat->can_knock['why'] ?? 'You cannot knock here.';

            return $row;
        }

        [$spec, $note, $href] = $this->knock($beat, $viewer);
        $row['knock'] = $spec;
        $row['knock_note'] = $note;
        $row['knock_href'] = $href;

        return $row;
    }

    /**
     * @param  iterable<Beat>  $beats
     * @return list<array<string, mixed>>
     */
    public function many(iterable $beats, User $viewer): array
    {
        $out = [];
        foreach ($beats as $beat) {
            $out[] = $this->present($beat, $viewer);
        }

        return $out;
    }

    /**
     * What the Knock button hands the shared sheet.
     *
     * @return array{0: ?array<string, mixed>, 1: ?string, 2: ?string} spec, a note when there is no button, a link for the note
     */
    private function knock(Beat $beat, User $viewer): array
    {
        $signal = $beat->signal;
        $entityId = $beat->from['entity']['id'] ?? $signal->entity_id;

        $spec = static fn (array $target, string $kind, ?string $type, ?int $id, string $label) => [
            'target' => $target,
            'kind' => $kind,
            'contextType' => $type,
            'contextId' => $id,
            'label' => $label,
            'title' => $beat->title,
        ];

        switch ($beat->kind) {
            case 'offer':
                return $entityId
                    ? [$spec(['entity' => (int) $entityId], 'enquiry', 'offer', (int) $signal->subject_id, 'Knock'), null, null]
                    : [null, null, null];

            case 'role':
                return $entityId
                    ? [$spec(['entity' => (int) $entityId], 'application', 'role', (int) $signal->subject_id, 'Apply'), null, null]
                    : [null, null, null];

            case 'need':
                return $signal->owner_id
                    ? [$spec(['user' => (int) $signal->owner_id], 'enquiry', 'need', (int) $signal->subject_id, 'Knock'), null, null]
                    : [null, null, null];

            case 'door':
                return [$spec(['door' => (int) $signal->subject_id], 'intro', 'door', (int) $signal->subject_id, 'Knock'), null, null];

            case 'business':
                return $entityId
                    ? [$spec(['entity' => (int) $entityId], 'enquiry', null, null, 'Knock'), null, null]
                    : [null, null, null];

            case 'collab':
                $target = $signal->entity_id ? ['entity' => (int) $signal->entity_id] : ($signal->owner_id ? ['user' => (int) $signal->owner_id] : null);

                return $target ? [$spec($target, 'collab', null, null, 'Knock'), null, null] : [null, null, null];

            case 'talent':
                $employer = $this->employerPage($viewer);

                if ($employer === null) {
                    return [null, 'Only an employer page can invite a professional.', pulse_route('entities.create')];
                }

                return [$spec(['signal' => (int) $signal->id, 'as_entity' => $employer], 'invite', null, null, 'Invite'), null, null];
        }

        return [null, null, null];
    }

    /** The first live employer page the person answers for, or null. */
    private function employerPage(User $viewer): ?int
    {
        return $this->employers[$viewer->id] ??= PulseEntity::query()
            ->where('is_employer', true)
            ->where('status', 'live')
            ->whereIn('id', fn ($q) => $q->select('entity_id')->from('pulse_entity_members')->where('user_id', $viewer->id)->whereIn('role', Access::RESPONDER_ROLES))
            ->orderBy('id')
            ->value('id');
    }

    /**
     * The small note in the corner of a beat: a price, a pay line, a budget. Only what the listing said.
     *
     * @return array{price?: array<string, mixed>, text?: string}|null
     */
    private function aside(Beat $beat): ?array
    {
        $s = $beat->subject;

        if ($s instanceof PulseOffer) {
            return match ($s->price_mode) {
                'fixed', 'from' => $s->amount_cents !== null
                    ? ['price' => ['amount' => $s->amount_cents / 100, 'currency' => $s->currency ?: 'ZAR', 'unit' => $s->price_mode === 'from' ? (filled($s->unit) ? $s->unit.', and up' : 'and up') : ($s->unit ?: null)]]
                    : null,
                'free' => ['text' => 'Free'],
                'quote' => ['text' => 'Ask for a quote'],
                default => null,
            };
        }

        if ($s instanceof PulseRole) {
            return filled($s->pay_text) ? ['text' => (string) $s->pay_text] : null;
        }

        if ($s instanceof PulseNeed) {
            return filled($s->budget_text) ? ['text' => 'Budget '.$s->budget_text] : null;
        }

        if ($beat->kind === 'door' && $beat->signal->expires_at) {
            return ['text' => 'Open until '.self::until($beat->signal->expires_at)];
        }

        return null;
    }

    /** @return array{tone: string, label: string}|null */
    private function verification(Beat $beat): ?array
    {
        $ladder = $beat->from['ladder'] ?? null;

        if (! $ladder || empty($ladder['label'])) {
            return null;
        }

        $label = (string) $ladder['label'];
        $tone = match (true) {
            str_starts_with($label, 'Checked') => 'good',
            str_starts_with($label, 'Claims') => 'warn',
            default => 'neutral',
        };

        return ['tone' => $tone, 'label' => $label];
    }

    /** "2 h ago", "Yesterday", "3 Nov". Short, because it is set in mono. */
    public static function ago(?CarbonInterface $at): ?string
    {
        if ($at === null) {
            return null;
        }

        $at = Carbon::instance($at);
        $minutes = (int) $at->diffInMinutes(now(), false);

        return match (true) {
            $minutes < 1 => 'Just now',
            $minutes < 60 => $minutes.' min ago',
            $minutes < 60 * 24 && $at->isToday() => (int) floor($minutes / 60).' h ago',
            $at->isYesterday() => 'Yesterday',
            $at->diffInDays(now()) < 7 => $at->format('l'),
            default => $at->format($at->year === now()->year ? 'j M' : 'j M Y'),
        };
    }

    /** "today 18:30", "tomorrow 07:00", "Sat 9 Nov, 18:30". */
    public static function until(CarbonInterface $at): string
    {
        $at = Carbon::instance($at)->timezone(config('app.timezone'));

        return match (true) {
            $at->isToday() => 'today '.$at->format('H:i'),
            $at->isTomorrow() => 'tomorrow '.$at->format('H:i'),
            default => $at->format('D j M, H:i'),
        };
    }
}
