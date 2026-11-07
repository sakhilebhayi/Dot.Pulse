<?php

namespace App\Livewire\Pulse\Floor;

use App\Livewire\Pulse\Floor\Concerns\ActsOnBeats;
use App\Livewire\Pulse\Floor\Support\BeatPresenter;
use App\Models\PulseDoor;
use App\Models\PulseEntity;
use App\Models\PulseMatch;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\PulseRole;
use App\Models\PulseSave;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\BeatBuilder;
use App\Services\Pulse\Discovery\ContextLoader;
use App\Services\Pulse\Discovery\Matcher;
use App\Services\Pulse\Discovery\SignalPack;
use App\Services\Pulse\Discovery\Taxonomy;
use App\Services\Pulse\Entities\ProfileService;
use App\Services\Pulse\Entities\VerificationService;
use App\Services\Pulse\Lines\ResponseStats;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One beat in full: the whole subject, the sync meter with every reason and why it is on your floor, who it comes from
 * with their verification ladder, how fast they answer, and the verbs (knock, save, report, mute, block).
 *
 * Privacy: an anonymous professional is drawn from the anonymous card only. Nothing here loads the person behind it.
 * A signal the person may not see (held, expired, blocked, muted, a professional they may not find) is a 404, the
 * same as one that does not exist.
 */
class BeatDetail extends Component
{
    use ActsOnBeats;

    #[Locked]
    public int $signalId;

    /** Plain facts about the subject, label => value. */
    #[Locked]
    public array $facts = [];

    /** The full text of the subject (description, details, body), if any. */
    #[Locked]
    public ?string $body = null;

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $rungs = [];

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $stats = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $entity = null;

    #[Locked]
    public bool $mine = false;

    /** A real match put this beat in front of the person, as opposed to a link or a search. */
    #[Locked]
    public bool $matched = false;

    #[Locked]
    public ?string $postUrl = null;

    #[Locked]
    public ?int $postId = null;

    public function mount(PulseSignal $signal, ContextLoader $contexts, Matcher $matcher, BeatBuilder $builder, BeatPresenter $presenter): void
    {
        $user = auth()->user();
        $this->signalId = $signal->id;

        $ctx = $contexts->loadExclusions($user->id);
        $pack = SignalPack::build(collect([$signal]));

        $this->mine = ($signal->owner_id !== null && (int) $signal->owner_id === $user->id)
            || ($signal->entity_id !== null && isset($ctx->ownEntityIds[(int) $signal->entity_id]));

        $open = $signal->status === 'live' && ($signal->expires_at === null || $signal->expires_at->isFuture());

        abort_unless($this->mine ? $open : $matcher->eligible($ctx, $signal, $pack), 404);

        $match = PulseMatch::query()->where('user_id', $user->id)->where('signal_id', $signal->id)->whereNull('dismissed_at')->first();
        $matches = $match ? [$signal->id => ['id' => $match->id, 'score' => $match->score, 'reasons' => (array) $match->reasons]] : [];
        $this->matched = $match !== null && ! $this->mine;
        $saved = PulseSave::query()->where('user_id', $user->id)->where('saveable_type', 'signal')->where('saveable_id', $signal->id)->exists();

        $beat = $builder->build($user->id, collect([$signal]), $pack, $matches, $saved ? [$signal->id => true] : [])->first();
        abort_if($beat === null, 404);

        $this->beats = [$presenter->present($beat, $user)];
        $this->loadSubject($beat->subject, $beat->kind, $beat->signal, (bool) $this->beats[0]['anonymous'], $user);
        $this->loadSource($beat, $user);
    }

    private function loadSubject(mixed $subject, string $kind, PulseSignal $signal, bool $anonymous, User $viewer): void
    {
        if ($anonymous) {
            return;
        }

        if ($subject instanceof PulseOffer) {
            $this->body = $subject->description ?: $subject->summary;
            $this->facts = array_filter([
                'Category' => $subject->category ? Taxonomy::label((string) $subject->category) : null,
                'Availability' => ['available' => 'Available', 'limited' => 'Limited', 'sold_out' => 'Sold out'][$subject->availability] ?? null,
                'Quotes' => $subject->accepts_quotes ? 'Takes quote requests' : null,
            ]);
        } elseif ($subject instanceof PulseNeed) {
            $this->body = $subject->details;
            $this->facts = array_filter([
                'Budget' => $subject->budget_text,
                'Needed by' => $subject->needed_by ? Carbon::parse($subject->needed_by)->format('j M Y') : null,
            ]);
        } elseif ($subject instanceof PulseRole) {
            $this->body = $subject->description ?: $subject->summary;
            $this->facts = array_filter([
                'Type' => ['full_time' => 'Full time', 'part_time' => 'Part time', 'contract' => 'Contract', 'internship' => 'Internship', 'freelance' => 'Freelance'][$subject->employment_type] ?? null,
                'Work' => $subject->work_mode ? ucfirst(str_replace('_', ' ', (string) $subject->work_mode)) : null,
                'Seniority' => $subject->seniority ? ucfirst((string) $subject->seniority) : null,
                'Pay' => $subject->pay_text,
                'Skills' => collect((array) $subject->skills)->map(fn ($s) => Taxonomy::label((string) $s))->take(10)->implode(', ') ?: null,
                'Closes' => $subject->closes_at ? Carbon::parse($subject->closes_at)->format('j M Y') : null,
            ]);
        } elseif ($subject instanceof PulsePost) {
            $this->body = $subject->body;
            $this->postId = $subject->id;
        } elseif ($subject instanceof PulseProfile) {
            $card = app(ProfileService::class)->card($subject, $viewer);
            $this->body = $card['bio'] ?? null;
            $this->facts = array_filter([
                'Skills' => collect((array) ($card['skills'] ?? []))->map(fn ($s) => Taxonomy::label((string) $s))->take(10)->implode(', ') ?: null,
                'Experience' => is_array($card['experience_band'] ?? null) ? ($card['experience_band']['label'] ?? null) : ($card['experience_band'] ?? null),
                'Open to' => collect((array) ($card['open_to'] ?? []))->map(fn ($s) => ucfirst(str_replace('_', ' ', (string) $s)))->implode(', ') ?: null,
            ]);
        } elseif ($subject instanceof PulseEntity) {
            $this->body = $subject->about ?: $subject->tagline;
            $this->facts = array_filter(['Industry' => $subject->industry]);
        } elseif ($kind === 'door') {
            $door = PulseDoor::query()->find($signal->subject_id);
            $this->body = $door?->purpose;
            $this->facts = array_filter([
                'Open until' => $door ? BeatPresenter::until($door->closes_at) : null,
                'How it works' => 'One knock each, with a short card. They decide who comes in.',
            ]);
        }
    }

    /** The business behind the beat: its ladder, its response record. Never for an anonymous professional. */
    private function loadSource(\App\Services\Pulse\Discovery\Beat $beat, User $viewer): void
    {
        if ($beat->from['anonymous'] ?? false) {
            return;
        }

        $signal = $beat->signal;

        if ($signal->entity_id) {
            $entity = PulseEntity::query()->find($signal->entity_id);

            if ($entity !== null) {
                $this->entity = ['name' => $entity->name, 'slug' => $entity->slug, 'tagline' => $entity->tagline];
                $this->rungs = app(VerificationService::class)->ladder($entity);
                $this->stats = ResponseStats::for($entity) + ['who' => $entity->name];
            }

            return;
        }

        if ($signal->owner_id && $beat->kind !== 'talent') {
            $owner = User::query()->find($signal->owner_id);

            if ($owner !== null) {
                $this->stats = ResponseStats::for($owner) + ['who' => $owner->name];
            }
        }
    }

    public function render(): View
    {
        $b = $this->beats[0] ?? null;
        $kind = $b['kind'] ?? null;

        $guide = null;
        if (in_array($kind, ['offer', 'need', 'business'], true)) {
            $guide = ['title' => 'Before you pay or meet', 'lines' => (array) config('pulse.safety.trade')];
        } elseif (in_array($kind, ['role', 'talent'], true)) {
            $guide = ['title' => 'Before you share or sign', 'lines' => (array) config('pulse.safety.work')];
        }

        return view('livewire.pulse.floor.detail', [
            'b' => $b,
            'guide' => $guide,
            'answer' => $this->stats ? self::answerLine($this->stats) : null,
        ]);
    }

    /**
     * The response record in words. With nothing received there is no record and the page says so.
     *
     * @param  array<string, mixed>  $s
     */
    public static function answerLine(array $s): string
    {
        if (($s['received'] ?? 0) < 1) {
            return 'No first messages received yet, so there is no response record.';
        }

        $rate = (int) round(((float) $s['response_rate']) * 100);
        $line = "Answered {$s['answered']} of {$s['received']} first messages in the last {$s['window_days']} days ({$rate} percent).";

        if (($s['median_first_response_seconds'] ?? null) !== null) {
            $sec = (int) $s['median_first_response_seconds'];
            $line .= ' Usually within '.match (true) {
                $sec < 3600 => max(1, (int) round($sec / 60)).' minutes',
                $sec < 86400 => (int) round($sec / 3600).' hours',
                default => (int) round($sec / 86400).' days',
            }.'.';
        }

        return $line;
    }
}
