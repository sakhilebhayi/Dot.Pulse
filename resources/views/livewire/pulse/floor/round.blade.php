@php
    $empties = [
        'all' => ['Nothing matches yet.', 'Say what you need, or what you are open to, and the floor will find it.'],
        'trade' => ['Nothing to trade yet.', 'Say what you need and the floor will find sellers. Offer something and it will find buyers.'],
        'work' => ['No work matches yet.', 'Tell the floor what you are open to, or post a role, and it will start introducing people.'],
        'local' => ['Nothing near you yet.', 'Local needs your place. Set where you are and the floor will look nearby first.'],
        'learn' => ['Nothing to read yet.', 'Questions, events and news that fit your interests show up here.'],
    ];
    [$emptyTitle, $emptyText] = $empties[$mode] ?? $empties['all'];
@endphp
<div class="fl-round" data-floor-round>
    <div class="fl-draw" wire:loading.delay.shortest wire:target="showNext,save,unsave,dismiss,removeReason" aria-hidden="true"></div>

    @if ($beats === [] && ! $everMatched)
        <x-pulse.empty :title="$emptyTitle" :text="$emptyText">
            <div class="pk-cluster">
                <x-pulse.button variant="signal" :href="pulse_route('needs.create')">Say what you need</x-pulse.button>
                <x-pulse.button variant="quiet" :href="pulse_route_first(['me.discover', 'profile.show'])">{{ $mode === 'local' ? 'Set your place' : 'Say what you are open to' }}</x-pulse.button>
            </div>
        </x-pulse.empty>
    @else
        @if ($beats !== [])
            <div class="pk-enter fl-list">
                @foreach ($beats as $b)
                    @include('livewire.pulse.floor.partials.beat', ['b' => $b, 'mode' => 'floor', 'why' => []])
                @endforeach
            </div>
        @endif

        @if ($hasMore)
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
                @if ($beats === [])
                    <p>Everything that fits you has been shown. New matches arrive as people post.</p>
                @endif
                <div class="pk-cluster fl-end__links">
                    <x-pulse.button variant="quiet" size="sm" :href="pulse_route('saved')">Your saved beats</x-pulse.button>
                    <x-pulse.button variant="quiet" size="sm" :href="pulse_route('needs.create')">Say what you need</x-pulse.button>
                </div>
            </div>
        @endif
    @endif

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
            } else if (event.key === 's' && current) {
                const save = current.querySelector('[data-act="save"]');
                if (save) { event.preventDefault(); save.click(); }
            } else if (event.key === 'Enter' && current && document.activeElement === current) {
                const open = current.querySelector('.pk-beat__title a');
                if (open) { event.preventDefault(); open.click(); }
            }
        };
        document.addEventListener('keydown', onKey);
    </script>
    @endscript
</div>
