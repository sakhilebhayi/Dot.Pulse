@php
    // First visit, per mode: what to do to get matches. Said once, plainly, with the three things that feed the matcher.
    $empties = [
        'all' => ['Nothing fits yet.', 'The floor introduces people once it knows you. Tell it what you do, what you need or who you follow.'],
        'trade' => ['Nothing to trade yet.', 'Say what you need and the floor finds sellers. Add an offer and it finds buyers.'],
        'work' => ['No work matches yet.', 'Say what you are open to and the floor starts introducing people. Hiring? Post a role from your business page.'],
        'local' => ['Nothing near you yet.', 'Local needs your place. Set where you are and the floor looks nearby first.'],
        'learn' => ['Nothing to read yet.', 'Questions, events and news that fit your interests show up here. Follow a business or add interests.'],
    ];
    [$emptyTitle, $emptyText] = $empties[$mode] ?? $empties['all'];
    $fresh = array_values(array_filter($beats, fn ($b) => empty($b['earlier'])));
    $earlier = array_values(array_filter($beats, fn ($b) => ! empty($b['earlier'])));
    $firstVisit = $fresh === [] && $earlier === [] && ! $everShown;
    // The beat in the pane (always one this round holds, see Round::validSelection). Not shown on a first visit.
    $selectedBeat = $firstVisit ? null : (collect($beats)->first(fn ($b) => (int) $b['signal_id'] === (int) $selected));
@endphp
<div class="fl-round" data-floor-round>
    <div class="fl-draw" wire:loading.delay.shortest wire:target="showNext,save,unsave,dismiss,removeReason,select" aria-hidden="true"></div>

    @if ($arrival)
        <x-pulse.arrival :headline="$headline" :lead="$lead" />
    @endif

    <div @class(['fl-work', 'fl-work--pane' => $selectedBeat !== null])>
    <div class="fl-work__main">

    @if ($firstVisit)
        @if ($knows !== [])
            {{-- The Floor, first visit, nothing matched: the reason is what the matcher holds about this person. Each line is their own record, with the place to change it. No one else is shown or implied. --}}
            <div class="pk-empty fl-knows" data-floor-empty>
                @unless ($arrival)<h2 class="pk-empty__title">{{ $emptyTitle }}</h2>@endunless
                <p class="pk-empty__text">The floor stays empty until there is a real reason to introduce someone. This is what it has to match you on so far.</p>
                <dl class="fl-knows__list">
                    @foreach ($knows as $k)
                        <div class="fl-knows__row" @if ($k['value'] === null) data-missing @endif>
                            <dt>{{ $k['label'] }}</dt>
                            <dd>
                                <span class="fl-knows__value">{{ $k['value'] ?? $k['missing'] }}</span>
                                @if ($k['href'] !== $firstHref)<a href="{{ $k['href'] }}">{{ $k['action'] }}</a>@endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @else
            <x-pulse.empty :title="$emptyTitle" :text="$emptyText" data-floor-empty>
                <div class="pk-cluster">
                    @foreach ($emptyActions as $action)
                        <x-pulse.button :variant="$loop->first ? 'signal' : 'quiet'" :href="$action['href']">{{ $action['label'] }}</x-pulse.button>
                    @endforeach
                </div>
            </x-pulse.empty>
        @endif
    @else
        @if ($fresh !== [])
            <div class="fl-lh">
                <h2 class="fl-lh__title">The round</h2>
                <span class="fl-lh__meta">{{ count($fresh) }} new{{ $earlier !== [] ? ', '.count($earlier).' seen earlier' : '' }}</span>
            </div>
            {{-- One 56px row per beat; the whole strand and the actions live in the pane beside the rows. --}}
            <ul class="fl-rows" role="list">
                @foreach ($fresh as $b)
                    @include('livewire.pulse.floor.partials.beat-row', ['b' => $b, 'selected' => $selected])
                @endforeach
            </ul>
        @endif

        @if (! $hasMore && $fresh === [] && $earlier !== [])
            {{-- A revisit with nothing new: one line, then what was seen. The beats (and their strands) stay the first thing on screen. --}}
            <p class="fl-caught" data-floor-end>
                <strong>That's the round. You are caught up.</strong>
                @if ($readyAt)<span>Next round at <span class="pk-mono">{{ $readyAt->format('H:i') }}</span>.</span>@endif
                <a href="{{ pulse_route('saved') }}">Your saved beats</a>
            </p>
        @elseif ($hasMore)
            <div class="fl-more">
                <x-pulse.button variant="primary" wire:click="showNext" wire:loading.attr="disabled" wire:target="showNext">Show the next batch</x-pulse.button>
                @if ($waiting > 0)
                    <p class="fl-small">{{ $waiting }} more in this round.</p>
                @endif
            </div>
        @else
            <div class="pk-end" data-floor-end>
                <h2>That's the round. You are caught up.</h2>
                @if ($readyAt)
                    <p>The next round is ready at <span class="pk-mono">{{ $readyAt->format('H:i') }}</span>.</p>
                @endif
                @if ($fresh === [])
                    <p>{{ $earlier !== [] ? 'Nothing new since you last looked. What you saw earlier is below for a day.' : 'Everything that fits you has been shown. New matches arrive as people post.' }}</p>
                @endif
                <div class="pk-cluster fl-end__links">
                    <x-pulse.button variant="quiet" size="sm" :href="pulse_route('saved')">Your saved beats</x-pulse.button>
                    <x-pulse.button variant="quiet" size="sm" :href="$endAction['href']">{{ $endAction['label'] }}</x-pulse.button>
                </div>
            </div>
        @endif

        @if ($earlier !== [])
            <section class="fl-earlier" aria-labelledby="fl-earlier-h">
                <h2 id="fl-earlier-h" class="fl-earlier__title">Seen earlier</h2>
                <p class="fl-small">Still here for a day, unless you save, dismiss or knock.</p>
                <ul class="fl-rows" role="list">
                    @foreach ($earlier as $b)
                        @include('livewire.pulse.floor.partials.beat-row', ['b' => $b, 'selected' => $selected, 'quiet' => true])
                    @endforeach
                </ul>
            </section>
        @endif
    @endif

    </div>

    @if ($selectedBeat !== null)
        <div class="fl-work__side">
            @include('livewire.pulse.floor.partials.beat-pane', ['b' => $selectedBeat])
        </div>
    @endif
    </div>

    @script
    <script>
        const root = $wire.$el; // seen beats, and the n / p / s / Enter keys: both only watch this round's own beats
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const queued = new Set();
        let timer = null;

        const flush = () => {
            timer = null;
            if (!queued.size || !root.isConnected) return;
            const ids = Array.from(queued);
            queued.clear();
            $wire.markSeen(ids);
        };

        const watcher = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                watcher.unobserve(entry.target);
                queued.add(Number(entry.target.dataset.beat));
            });
            if (queued.size && !timer) timer = setTimeout(flush, 1200);
        }, { threshold: 0.6 }) : null;

        const watch = () => {
            if (!watcher) return;
            root.querySelectorAll('[data-beat]:not([data-watched])').forEach((el) => {
                el.setAttribute('data-watched', '');
                watcher.observe(el);
            });
        };
        watch();
        new MutationObserver(watch).observe(root, { childList: true, subtree: true });

        const beats = () => Array.from(root.querySelectorAll('[data-beat]'));

        const typing = (el) => el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));

        const onKey = (event) => {
            if (!root.isConnected) { document.removeEventListener('keydown', onKey); return; }
            if (event.metaKey || event.ctrlKey || event.altKey || typing(event.target) || document.querySelector('dialog[open]')) return;

            const list = beats();
            if (!list.length) return;
            const current = document.activeElement && document.activeElement.closest ? document.activeElement.closest('[data-beat]') : null;
            const at = current ? list.indexOf(current) : -1;

            if (event.key === 'n' || event.key === 'p') {
                const to = event.key === 'n' ? Math.min(at + 1, list.length - 1) : Math.max(at - 1, 0);
                event.preventDefault();
                list[to].focus({ preventScroll: true });
                list[to].scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
                // A row is also what the pane shows: moving along the rows moves the pane (it is the same beat, one round trip).
                if (list[to].hasAttribute('data-row') && window.matchMedia('(min-width: 1200px)').matches) $wire.select(Number(list[to].dataset.beat));
            } else if (event.key === 's' && current) {
                // A card carries its own Save; a row's Save is in the pane when the pane shows that beat, otherwise save it directly.
                const id = current.dataset.beat;
                const save = current.querySelector('[data-act="save"]') || root.querySelector('[data-pane-beat="' + id + '"] [data-act="save"]');
                if (save) { event.preventDefault(); save.click(); }
                else if (current.hasAttribute('data-row')) { event.preventDefault(); $wire.save(Number(id)); }
            } else if (event.key === 'Enter' && current && document.activeElement === current) {
                // Enter on a focused row or card opens the beat's own page (a click on a row only selects it on a wide screen).
                const open = current.querySelector('.pk-beat__title a, .fl-row__open');
                if (open) { event.preventDefault(); window.location.assign(open.href); }
            }
        };
        document.addEventListener('keydown', onKey);
    </script>
    @endscript
</div>
