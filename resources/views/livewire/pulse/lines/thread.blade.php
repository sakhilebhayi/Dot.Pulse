@php
    use App\Livewire\Pulse\Lines\Support\Words;
    $cp = $line['counterpart'];
    $side = $line['side'];
    $partyName = $cp['kind'] === 'anonymous' ? ($cp['alias'] ?? 'Anonymous professional') : $cp['name'];
    $state = Words::state($line);
    $status = $line['status'];
    $lastSide = $model->last_message_side;
    $awaitingReply = $status === 'open' && $lastSide !== null && $lastSide !== $side && $model->last_message_at?->lte(now()->subHour());
    $canDecide = $side === 'initiator';
    $canReport = $side !== null;
    $iAmInvited = $line['can']['reveal'];
    $anonymousToThem = $model->identity_hidden && $side === 'recipient';
@endphp
<div class="ln-thread" wire:poll.visible.10s="refresh"
     x-data x-init="$nextTick(() => { const last = $el.querySelector('[data-latest]'); if (last && !location.hash) last.scrollIntoView({ block: 'end' }); })">
    <div class="ln-back"><a href="{{ route('lines') }}">All lines</a></div>

    <header class="ln-head">
        @include('livewire.pulse.lines.partials.party', ['cp' => $cp, 'size' => 'lg'])
        <div class="ln-head__who">
            <div class="ln-who ln-who--lg">
                @if ($cp['kind'] === 'person' && ! empty($cp['user_id']) && ($cp['linkable'] ?? true) && Route::has('users.show'))
                    <a href="{{ route('users.show', $cp['user_id']) }}">{{ $partyName }}</a>
                @elseif ($cp['kind'] === 'entity' && ! empty($cp['slug']) && Route::has('entities.show'))
                    <a href="{{ route('entities.show', $cp['slug']) }}">{{ $partyName }}</a>
                @else
                    {{ $partyName }}
                @endif
            </div>
            <div class="ln-meta">
                <x-pulse.pill :tone="$state['tone']">{{ $state['label'] }}</x-pulse.pill>
                <x-pulse.kind-word :kind="Words::kind($line['kind'])" />
                @if ($cp['kind'] === 'anonymous')<span>Anonymous until they show who they are</span>@endif
            </div>
        </div>
        <div class="ln-tools" role="group" aria-label="Line actions">
            <button type="button" class="ln-linkbtn" wire:click="toggleMute" aria-pressed="{{ $muted ? 'true' : 'false' }}">{{ $muted ? 'Unmute' : 'Mute' }}</button>
            <button type="button" class="ln-linkbtn" wire:click="toggleArchive" aria-pressed="{{ $archived ? 'true' : 'false' }}">{{ $archived ? 'Unarchive' : 'Archive' }}</button>
            @if ($line['can']['close'])
                <button type="button" class="ln-linkbtn" wire:click="ask('close')">{{ $status === 'requested' ? 'Withdraw' : 'Close' }}</button>
            @endif
            @if ($line['can']['reveal'])
                <button type="button" class="ln-linkbtn" wire:click="ask('reveal')">Show who I am</button>
            @endif
            @if ($side !== null && $cp['kind'] !== 'anonymous')
                <button type="button" class="ln-linkbtn" wire:click="askBlock({{ $line['id'] }})">Block</button>
            @endif
            @if ($side !== null)
                <livewire:pulse.lines.report subject-type="conversation" :subject-id="$line['id']" :key="'report-line-'.$line['id']" />
            @endif
        </div>
    </header>

    @if ($failure)
        <p class="ln-alert" role="alert"><x-pulse.icon name="alert" :size="16" />{{ $failure }}</p>
    @endif

    @if ($confirming === 'close')
        <div class="ln-confirm" role="group" aria-label="Confirm close">
            <p><strong>{{ $status === 'requested' ? 'Withdraw this request?' : 'Close this line?' }}</strong> It moves to Closed and nobody can send a message. {{ $status === 'requested' ? 'They will see it as closed.' : 'You can still mark what it came to.' }}</p>
            <div class="pk-cluster">
                <x-pulse.button variant="primary" size="sm" wire:click="closeThis">{{ $status === 'requested' ? 'Withdraw it' : 'Close it' }}</x-pulse.button>
                <x-pulse.button variant="quiet" size="sm" wire:click="cancelAsk">Keep it open</x-pulse.button>
            </div>
        </div>
    @elseif ($confirming === 'reveal')
        <div class="ln-confirm" role="group" aria-label="Confirm showing who you are">
            <p><strong>Show them who you are?</strong> They will see your name and profile. This cannot be undone, and only you can do it.</p>
            <div class="pk-cluster">
                <x-pulse.button variant="primary" size="sm" wire:click="reveal">Show who I am</x-pulse.button>
                <x-pulse.button variant="quiet" size="sm" wire:click="cancelAsk">Stay anonymous</x-pulse.button>
            </div>
        </div>
    @elseif ($blocking === $line['id'] && ! ($status === 'requested' && $side === 'recipient'))
        <div class="ln-confirm" role="group" aria-label="Confirm block">
            <p><strong>Block {{ $partyName }}?</strong> You will not see each other and every open line between you closes. They are not told.</p>
            <div class="pk-cluster">
                <x-pulse.button variant="danger" size="sm" wire:click="confirmBlock">Block</x-pulse.button>
                <x-pulse.button variant="quiet" size="sm" wire:click="cancelBlock">Back</x-pulse.button>
            </div>
        </div>
    @endif

    @if ($reportingMessage)
        <div class="pk-card ln-reportbox">
            <livewire:pulse.lines.report subject-type="message" :subject-id="$reportingMessage" :start-open="true" :key="'report-msg-'.$reportingMessage" />
            <button type="button" class="ln-linkbtn" wire:click="stopReporting">Close this</button>
        </div>
    @endif

    @include('livewire.pulse.lines.partials.context-card', ['line' => $line, 'subject' => $subject, 'open' => false])

    @if ($anonymousToThem)
        <p class="ln-note ln-note--block"><x-pulse.icon name="lock" :size="16" />This employer sees you as an anonymous card: your headline, skills, experience band and region. Nothing else. Show who you are only when you are ready.</p>
    @elseif ($cp['kind'] === 'anonymous')
        <p class="ln-note ln-note--block"><x-pulse.icon name="lock" :size="16" />Their name stays hidden until they choose to show it. You see their card and nothing more.</p>
    @endif

    {{-- A request waiting on you: answer it here. --}}
    @if ($status === 'requested' && $side === 'recipient')
        <section class="pk-card ln-answer" aria-labelledby="ln-answer-h">
            <h2 id="ln-answer-h" class="ln-subhead">Waiting for your answer</h2>
            <p class="ln-nudge">They have been waiting {{ Words::span($line['created_at']) }}. Answer, say not now, or let it lapse; it closes as no reply on {{ Words::date($line['expires_at']) }}.</p>
            @include('livewire.pulse.lines.partials.request-actions', ['line' => $line, 'ignored' => $model->ignored_at !== null])
        </section>
    @elseif ($status === 'requested' && $side === 'initiator')
        <section class="pk-card ln-answer" aria-labelledby="ln-wait-h">
            <h2 id="ln-wait-h" class="ln-subhead">Waiting for them</h2>
            <p class="ln-nudge">Asked {{ Words::span($line['created_at']) }} ago. They can say yes, say not now, or let it lapse. If nobody answers, it closes as no reply on {{ Words::date($line['expires_at']) }} and you are told.</p>
        </section>
    @elseif ($status === 'declined' && $side === 'initiator')
        <p class="ln-note ln-note--block"><x-pulse.icon name="info" :size="16" />{{ $line['declined_label'] ?: 'Not now' }}. That is an answer, not a verdict. You can ask again in a few weeks.</p>
    @elseif ($status === 'closed' && ($line['closed_reason'] ?? null) === 'expired')
        <p class="ln-note ln-note--block"><x-pulse.icon name="info" :size="16" />{{ $side === 'initiator' ? 'Closed, no reply. Nobody answered in time. That is not on you.' : 'Lapsed. Nobody answered in time, so it closed.' }}</p>
    @elseif ($status === 'closed' && ($line['closed_reason'] ?? null) === 'not_chosen')
        <p class="ln-note ln-note--block"><x-pulse.icon name="info" :size="16" />They went with another business for this one. Thank you for replying.</p>
    @endif

    @if ($awaitingReply)
        <p class="ln-nudge ln-nudge--card">Waiting for your reply for {{ Words::span($model->last_message_at) }}. Reply below, or close the line if it has run its course.</p>
    @endif

    @if (in_array($status, ['requested', 'open'], true) && ! $line['outcome']['confirmed'])
        @include('livewire.pulse.lines.partials.handover', ['line' => $line, 'lineId' => $line['id']])
    @endif

    {{-- The business answers a quote request on a slip. --}}
    @if ($slipOpen)
        @include('livewire.pulse.lines.partials.slip-form')
    @endif

    @if ($compareUrl)
        <p class="ln-compare"><a href="{{ $compareUrl }}">Compare all the quotes for this request</a></p>
    @endif

    <section class="ln-messages" aria-label="Messages" aria-live="polite">
        @php $day = null; @endphp
        @forelse ($messages as $m)
            @php $thisDay = $m['created_at']?->format('Y-m-d'); @endphp
            @if ($thisDay !== $day)
                @php $day = $thisDay; @endphp
                <h2 class="ln-day">{{ $m['created_at']?->isToday() ? 'Today' : ($m['created_at']?->isYesterday() ? 'Yesterday' : Words::date($m['created_at'])) }}</h2>
            @endif
            <div wire:key="m-{{ $m['id'] }}" @if ($loop->last) data-latest @endif>
                @include('livewire.pulse.lines.partials.message', ['m' => $m, 'viewerSide' => $side, 'quotes' => $quotes, 'canDecide' => $canDecide, 'canReport' => $canReport, 'closedReason' => $line['closed_reason'] ?? null])
            </div>
        @empty
            <p class="ln-muted">Nothing said yet.</p>
        @endforelse
    </section>

    @if ($outcomeState['visible'])
        @include('livewire.pulse.lines.partials.outcome-bar', ['o' => $outcomeState, 'line' => $line])
    @endif

    @if ($reviewState['show'])
        @include('livewire.pulse.lines.partials.review', ['r' => $reviewState])
    @endif

    @if ($line['can']['send'])
        @include('livewire.pulse.lines.partials.composer', ['line' => $line, 'limit' => $limit])
    @elseif ($status === 'open')
        <p class="ln-muted">You can read this line but not write in it.</p>
    @elseif ($status !== 'requested')
        <p class="ln-muted ln-ended">This line is over. Nobody can add to it.</p>
    @endif
</div>
